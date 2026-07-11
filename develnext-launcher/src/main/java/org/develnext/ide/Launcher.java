package org.develnext.ide;

import javafx.application.Platform;
import javafx.embed.swing.SwingNode;

import javax.swing.*;
import java.io.*;
import java.net.URISyntaxException;
import java.util.*;

public class Launcher {
    public static final String[] defaultJvmArgs = {
            "-Xms256M", "-XX:ReservedCodeCacheSize=150m", "-XX:+UseConcMarkSweepGC", "-Dsun.io.useCanonCaches=false",
            "-Djava.net.preferIPv4Stack=true", "-Dfile.encoding=UTF-8", "-Ddevelnext.launcher=root"
    };

    protected ProcessBuilder processBuilder;
    protected Process process;
    private File rootDir;

    protected String[] fetchJvmArgs() {
        String[] jvmArgs = defaultJvmArgs;

        if (new File(rootDir, "/DevelNext.l4j.ini").exists()) {
            try {
                Scanner scanner = new Scanner(new FileInputStream(new File(rootDir, "/DevelNext.l4j.ini")), "UTF-8");

                Set<String> newJvmArgs = new TreeSet<String>();

                while (scanner.hasNextLine()) {
                    String line = scanner.nextLine().trim();

                    if (line.isEmpty() || line.startsWith("#")) {
                        continue;
                    }

                    newJvmArgs.add(line);
                }

                jvmArgs = newJvmArgs.toArray(new String[newJvmArgs.size()]);

            } catch (FileNotFoundException e) {
                e.printStackTrace();
            }
        }

        return jvmArgs;
    }

    private static String[] concatArrays(String[] first, String[] second) {
        List<String> both = new ArrayList<String>(first.length + second.length);
        Collections.addAll(both, first);
        Collections.addAll(both, second);
        return both.toArray(new String[both.size()]);
    }

    public boolean isJava8FxExists() {
        try {
            Class.forName("javafx.application.Platform");
            Class.forName("javafx.embed.swing.SwingNode");
            return true;
        } catch (ClassNotFoundException e) {
            return false;
        }
    }

    public void start() throws URISyntaxException, IOException, InterruptedException {
        if (!isJava8FxExists()) {
            JOptionPane.showMessageDialog(null, "Oracle/Open Java Runtime 8+ required with JavaFX", "Error", JOptionPane.ERROR_MESSAGE);
            return;
        }

        rootDir = new File(Launcher.class.getProtectionDomain().getCodeSource().getLocation().toURI().getPath()).getParentFile();

        String[] jvmArgs = fetchJvmArgs();

        // Use the java binary that actually launched this process rather than a literal
        // "java" resolved via PATH: PATH isn't guaranteed to point at the bundled runtime
        // (e.g. a macOS .app launched via Finder/Dock gets a minimal PATH from launchd).
        String javaBin = System.getProperty("java.home") + File.separator + "bin" + File.separator + "java";
        jvmArgs = concatArrays(new String[]{javaBin}, jvmArgs);

        String[] args = concatArrays(jvmArgs, new String[]{
                "-Ddevelnext.launcher=root",
                "-Ddevelnext.path=" + rootDir.getAbsolutePath(), "-cp", rootDir.getAbsolutePath() + "/lib/*", "org.develnext.jphp.ext.javafx.FXLauncher"
        });

        System.out.print(join(args, " "));

        processBuilder = new ProcessBuilder(args);
        processBuilder.inheritIO();
        process = processBuilder.start();

        // Wait for the real app instead of exiting right after spawning it: on macOS, this
        // process is what launchd/RunningBoard track as "the app" for the whole bundle. If it
        // exits immediately (as a fire-and-forget ProcessBuilder.start() would), macOS treats
        // the app as quit and the orphaned child's windows never get composited by WindowServer,
        // even though the child process itself keeps running fine. Harmless on Windows/Linux.
        System.exit(process.waitFor());
    }

    public static void main(String[] args) throws URISyntaxException, IOException, InterruptedException {
        new Launcher().start();
    }

    public static String join(Object[] array, String separator, int startIndex, int endIndex) {
        if (array == null) {
            return null;
        }
        if (separator == null) {
            separator = "";
        }

        // endIndex - startIndex > 0:   Len = NofStrings *(len(firstString) + len(separator))
        //           (Assuming that all Strings are roughly equally long)
        int noOfItems = endIndex - startIndex;
        if (noOfItems <= 0) {
            return "";
        }

        StringBuilder buf = new StringBuilder(noOfItems * 16);

        for (int i = startIndex; i < endIndex; i++) {
            if (i > startIndex) {
                buf.append(separator);
            }
            if (array[i] != null) {
                buf.append(array[i]);
            }
        }
        return buf.toString();
    }

    public static String join(Object[] array, String separator) {
        if (array == null) {
            return null;
        }
        return join(array, separator, 0, array.length);
    }
}
