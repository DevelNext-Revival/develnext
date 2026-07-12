<?php
namespace ide\project\control;

use git\Git;
use git\GitAPIException;
use ide\forms\MessageBoxForm;
use ide\Ide;
use ide\utils\UiUtils;
use php\gui\layout\UXHBox;
use php\gui\layout\UXVBox;
use php\gui\text\UXFont;
use php\gui\UXButton;
use php\gui\UXCheckbox;
use php\gui\UXLabel;
use php\gui\UXListCell;
use php\gui\UXListView;
use php\gui\UXSeparator;
use php\gui\UXTextArea;
use php\io\IOException;
use php\lang\Thread;
use php\lib\arr;
use php\lib\str;

/**
 * Core git workflow for the currently open project: branch, file status with
 * stage/unstage, commit, push, pull, and a short recent-commits log. Backed by
 * jphp-git-ext's git\Git (a JGit wrapper) -- see its full API at
 * 3rd-party/jphp/jphp-git-ext/src/main/resources/JPHP-INF/sdk/git/Git.php.
 *
 * Not in this pass: file-tree status badges, branch switching/creation, stash UI,
 * in-app credentials management, cloning a new project from a URL.
 */
class GitProjectControlPane extends AbstractProjectControlPane
{
    /** @var Git */
    protected $git;

    protected $notARepoPane;
    protected $repoPane;

    protected $branchLabel;
    protected $statusList;
    protected $commitMessageArea;
    protected $logList;

    /** @var array */
    protected $statusRows = [];

    public function getName()
    {
        return 'Git';
    }

    public function getDescription()
    {
        return 'Статус, коммиты и синхронизация с удаленным репозиторием';
    }

    public function getIcon()
    {
        // No dedicated git icon in the icon set; cloud16.png is the closest existing fit
        // for a "sync with remote" concept.
        return 'icons/cloud16.png';
    }

    protected function project()
    {
        return Ide::project();
    }

    protected function openGit()
    {
        if (!$this->git && $this->project()) {
            try {
                $this->git = new Git($this->project()->getRootDir());
            } catch (IOException $e) {
                $this->git = null;
            }
        }

        return $this->git;
    }

    public function open()
    {
        $this->refresh();
    }

    protected function runInBackground(callable $action, callable $onDone = null)
    {
        $thread = new Thread(function () use ($action, $onDone) {
            $error = null;

            try {
                $action();
            } catch (\Exception $e) {
                // Catch broadly, not just GitAPIException/IOException: JGit throws many
                // GitAPIException subclasses (e.g. NoHeadException on a brand new repo
                // with zero commits, hit when refresh() calls log() right after Init),
                // and jphp doesn't appear to map every one back to the git\GitAPIException
                // PHP wrapper specifically -- catching those narrow types let this one
                // through uncaught up to the IDE's global error dialog.
                $error = $e->getMessage();
            }

            uiLater(function () use ($error, $onDone) {
                if ($error) {
                    MessageBoxForm::warning($error);
                }

                if ($onDone) {
                    $onDone($error);
                }
            });
        });

        $thread->setName('thread-git-' . str::random());
        $thread->start();
    }

    protected function makeNotARepoUi()
    {
        $label = new UXLabel('Проект не находится под управлением git.');
        $label->font = UXFont::of('System', UiUtils::fontSize());

        $initButton = new UXButton('Инициализировать репозиторий');
        $initButton->on('click', function () {
            $project = $this->project();

            if (!$project) {
                return;
            }

            $directory = $project->getRootDir();

            // Git's own constructor requires a .git dir to already exist (it's Git::open()
            // under the hood), so a brand new repo has to go through this static factory
            // instead -- see Git::create() in jphp-git-ext's WrapGit.java.
            $this->runInBackground(function () use ($directory) {
                Git::create($directory);
            }, function ($error) {
                if (!$error) {
                    $this->git = null;
                    $this->refresh();
                }
            });
        });

        $box = new UXVBox([$label, $initButton], 10);
        $box->padding = 10;

        return $this->notARepoPane = $box;
    }

    protected function makeRepoUi()
    {
        $this->branchLabel = new UXLabel('');
        $this->branchLabel->font = UXFont::of('System', UiUtils::fontSize(), 'BOLD');

        $refreshButton = new UXButton('Обновить');
        $refreshButton->on('click', function () {
            $this->refresh();
        });

        $header = new UXHBox([$this->branchLabel, $refreshButton], 10);

        $this->statusList = new UXListView();
        $this->statusList->fixedCellSize = 24;
        $this->statusList->setCellFactory(function (UXListCell $cell, $index) {
            $this->makeStatusRowUi($cell, $index);
        });

        $statusTitle = new UXLabel('Изменения');
        $statusTitle->font = UXFont::of('System', UiUtils::fontSize(), 'BOLD');

        $this->commitMessageArea = new UXTextArea();
        $this->commitMessageArea->promptText = 'Сообщение коммита';
        $this->commitMessageArea->prefRowCount = 3;
        $this->commitMessageArea->wrapText = true;

        $commitButton = new UXButton('Commit');
        $commitButton->on('click', function () {
            $this->doCommit();
        });

        $pushButton = new UXButton('Push');
        $pushButton->on('click', function () {
            $this->doPush();
        });

        $pullButton = new UXButton('Pull');
        $pullButton->on('click', function () {
            $this->doPull();
        });

        $actions = new UXHBox([$commitButton, $pushButton, $pullButton], 5);

        $logTitle = new UXLabel('Последние коммиты');
        $logTitle->font = UXFont::of('System', UiUtils::fontSize(), 'BOLD');

        $this->logList = new UXListView();
        $this->logList->fixedCellSize = 20;
        $this->logList->setCellFactory(function (UXListCell $cell, $item) {
            $cell->text = $item;
        });

        $box = new UXVBox([
            $header,
            new UXSeparator(),
            $statusTitle,
            $this->statusList,
            $this->commitMessageArea,
            $actions,
            new UXSeparator(),
            $logTitle,
            $this->logList,
        ], 8);

        $box->padding = 10;

        UXVBox::setVgrow($this->statusList, 'ALWAYS');
        UXVBox::setVgrow($this->logList, 'ALWAYS');

        return $this->repoPane = $box;
    }

    protected function makeStatusRowUi(UXListCell $cell, $index)
    {
        $row = $this->statusRows[$index];

        if (!$row) {
            $cell->graphic = null;
            return;
        }

        $checkbox = new UXCheckbox();
        $checkbox->selected = $row['staged'];
        $checkbox->on('action', function () use ($index, $checkbox) {
            $this->statusRows[$index]['staged'] = $checkbox->selected;
        });

        $statusLabel = new UXLabel($row['letter']);
        $statusLabel->prefWidth = 20;
        $statusLabel->font = UXFont::of('Consolas', UiUtils::fontSize(), 'BOLD');

        $pathLabel = new UXLabel($row['path']);

        $cell->graphic = new UXHBox([$checkbox, $statusLabel, $pathLabel], 5);
    }

    protected function makeUi()
    {
        $root = new UXVBox();

        $this->makeNotARepoUi();
        $this->makeRepoUi();

        $root->add($this->repoPane);
        $root->add($this->notARepoPane);

        UXVBox::setVgrow($this->repoPane, 'ALWAYS');
        UXVBox::setVgrow($this->notARepoPane, 'ALWAYS');

        return $root;
    }

    protected function showRepoState($exists)
    {
        $this->repoPane->visible = $exists;
        $this->repoPane->managed = $exists;

        $this->notARepoPane->visible = !$exists;
        $this->notARepoPane->managed = !$exists;
    }

    public function refresh()
    {
        $git = $this->openGit();

        if (!$this->ui) {
            return;
        }

        if (!$git) {
            $this->showRepoState(false);
            return;
        }

        $this->runInBackground(function () use ($git) {
            $exists = $git->isExists();

            $branch = null;
            $status = null;
            $log = null;

            if ($exists) {
                $branch = $git->getBranch();
                $status = $git->status();

                // log() throws (NoHeadException) on a repo with zero commits yet, e.g.
                // right after Init -- that shouldn't stop branch/status from showing.
                try {
                    $log = $git->log(['maxCount' => 15]);
                } catch (\Exception $e) {
                    $log = [];
                }
            }

            uiLater(function () use ($exists, $branch, $status, $log) {
                $this->showRepoState($exists);

                if (!$exists) {
                    return;
                }

                $this->branchLabel->text = "Ветка: $branch";
                $this->updateStatusRows($status);
                $this->updateLog($log);
            });
        });
    }

    protected function updateStatusRows($status)
    {
        $letters = [
            'added' => 'A',
            'untracked' => '?',
            'modified' => 'M',
            'changed' => 'M',
            'missing' => 'D',
            'removed' => 'D',
            'conflicting' => 'C',
        ];

        $rows = [];
        $seen = [];

        foreach ($letters as $category => $letter) {
            foreach ((array)$status[$category] as $path) {
                if ($seen[$path]) {
                    continue;
                }

                $seen[$path] = true;

                $rows[] = [
                    'path' => $path,
                    'letter' => $letter,
                    'staged' => false,
                ];
            }
        }

        $this->statusRows = $rows;

        $this->statusList->items->clear();

        foreach ($rows as $i => $row) {
            $this->statusList->items->add($i);
        }
    }

    protected function updateLog($log)
    {
        $this->logList->items->clear();

        foreach ((array)$log as $entry) {
            $shortId = str::sub($entry['id'] ?: $entry['name'] ?: '', 0, 7);
            $message = str::lines($entry['fullMessage'] ?: $entry['shortMessage'] ?: '')[0];

            $this->logList->items->add(str::trim("$shortId $message"));
        }
    }

    protected function stagedPaths()
    {
        $paths = [];

        foreach ($this->statusRows as $row) {
            if ($row['staged']) {
                $paths[] = $row['path'];
            }
        }

        return $paths;
    }

    protected function doCommit()
    {
        $git = $this->openGit();

        if (!$git) {
            return;
        }

        $paths = $this->stagedPaths();
        $message = str::trim($this->commitMessageArea->text);

        if (!$paths) {
            Ide::toast('Нет отмеченных файлов для коммита.');
            return;
        }

        if (!$message) {
            Ide::toast('Введите сообщение коммита.');
            return;
        }

        $this->runInBackground(function () use ($git, $paths, $message) {
            foreach ($paths as $path) {
                $git->add($path);
            }

            // noVerify: JGit's commit() runs the repo's own pre-commit/commit-msg/
            // post-commit hooks by default (whatever executable scripts happen to sit
            // in .git/hooks or wherever core.hooksPath points), exactly like the real
            // git CLI does. Unlike a terminal user who explicitly opted into that repo
            // and its hooks, a DevelNext user opening a downloaded/shared project has
            // no reason to expect clicking "Commit" in an IDE panel executes arbitrary
            // scripts from the project. Skip hooks here; a real hook-running workflow
            // (e.g. via a real git CLI/terminal) is still unaffected by this panel.
            $git->commit($message, ['noVerify' => true]);
        }, function ($error) {
            if (!$error) {
                $this->commitMessageArea->text = '';
                Ide::toast('Коммит создан.');
                $this->refresh();
            }
        });
    }

    protected function remoteName()
    {
        $git = $this->openGit();

        try {
            $remotes = $git->remoteList();
        } catch (GitAPIException $e) {
            return 'origin';
        }

        foreach ((array)$remotes as $remote) {
            if ($remote['name'] === 'origin') {
                return 'origin';
            }
        }

        $first = arr::first($remotes);

        return $first ? $first['name'] : 'origin';
    }

    protected function doPush()
    {
        $git = $this->openGit();

        if (!$git) {
            return;
        }

        $remote = $this->remoteName();

        $this->runInBackground(function () use ($git, $remote) {
            $git->push($remote);
        }, function ($error) {
            if (!$error) {
                Ide::toast('Push выполнен успешно.');
            }
        });
    }

    protected function doPull()
    {
        $git = $this->openGit();

        if (!$git) {
            return;
        }

        $remote = $this->remoteName();

        $this->runInBackground(function () use ($git, $remote) {
            $git->pull($remote);
        }, function ($error) {
            if (!$error) {
                Ide::toast('Pull выполнен успешно.');
                $this->refresh();
            }
        });
    }
}
