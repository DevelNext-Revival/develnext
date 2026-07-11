package org.develnext.jphp.ext.javafx.tabs.support.skin;

import java.util.IdentityHashMap;
import java.util.Map;
import javafx.collections.ListChangeListener;
import javafx.scene.control.Tab;
import javafx.scene.control.TabPane;
import javafx.scene.control.skin.TabPaneSkin;
import org.develnext.jphp.ext.javafx.tabs.support.DndTabPane;

/**
 * Fallback for {@link DnDTabPaneSkin} on JavaFX 9+: JavaFX 9 rewrote TabPaneSkin's internals
 * as public API (a different class, in a different package, with a different structure), so
 * the FX8-only reflective skin can't load there at all. This relies on JavaFX's own built-in
 * same-pane tab-reorder support ({@link javafx.scene.control.TabPane.TabDragPolicy#REORDER})
 * instead of reimplementing drag handling, since that's the only behavior DevelNext actually
 * uses (see DndTabPaneFactory.createDefaultDnDPane callers) -- cross-pane/cross-window drag
 * was never wired up.
 *
 * The one thing the built-in policy doesn't support is per-tab "undraggable" tabs (used to
 * keep the "+" add-tab button pinned in place); this replicates that by snapping any tab
 * marked undraggable back to its last known index whenever the tab order is permuted.
 */
public class DnDTabPaneSkinFx9 extends TabPaneSkin {
    private final Map<Tab, Integer> pinnedIndices = new IdentityHashMap<>();

    public DnDTabPaneSkinFx9(TabPane tabPane) {
        super(tabPane);

        tabPane.setTabDragPolicy(TabPane.TabDragPolicy.REORDER);

        if (tabPane instanceof DndTabPane) {
            DndTabPane dndTabPane = (DndTabPane) tabPane;
            resyncPinnedIndices(dndTabPane);

            dndTabPane.getTabs().addListener((ListChangeListener<Tab>) change -> {
                boolean permutationOnly = true;

                while (change.next()) {
                    if (change.wasAdded() || change.wasRemoved()) {
                        permutationOnly = false;
                    }
                }

                if (permutationOnly) {
                    restorePinnedIndices(dndTabPane);
                } else {
                    resyncPinnedIndices(dndTabPane);
                }
            });
        }
    }

    private void resyncPinnedIndices(DndTabPane tabPane) {
        pinnedIndices.clear();

        for (Tab tab : tabPane.getTabs()) {
            if (!tabPane.isDraggableTab(tab)) {
                pinnedIndices.put(tab, tabPane.getTabs().indexOf(tab));
            }
        }
    }

    private void restorePinnedIndices(DndTabPane tabPane) {
        for (Map.Entry<Tab, Integer> entry : pinnedIndices.entrySet()) {
            Tab tab = entry.getKey();
            int expectedIndex = Math.min(entry.getValue(), tabPane.getTabs().size() - 1);
            int actualIndex = tabPane.getTabs().indexOf(tab);

            if (actualIndex >= 0 && actualIndex != expectedIndex) {
                tabPane.getTabs().remove(actualIndex);
                tabPane.getTabs().add(expectedIndex, tab);
            }
        }
    }
}
