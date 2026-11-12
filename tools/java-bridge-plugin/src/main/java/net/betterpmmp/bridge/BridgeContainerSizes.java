/*
 * BetterPMMP-Bridge - ViaProxy plugin for BetterPMMP's Java bridge.
 * GPL-3.0-or-later (extends ViaProxy/ViaBedrock, which are GPL-3.0).
 */
package net.betterpmmp.bridge;

import com.viaversion.viaversion.api.connection.StorableObject;

import java.util.HashMap;
import java.util.Map;

/** Window details announced by BetterPMMP right before it opens a window: window id -> size and heading. */
public final class BridgeContainerSizes implements StorableObject {

    public record Announced(int size, String name) {
    }

    private final Map<Integer, Announced> windows = new HashMap<>();

    public void put(final int windowId, final int size, final String name) {
        this.windows.put(windowId, new Announced(size, name));
    }

    public Announced take(final int windowId) {
        return this.windows.remove(windowId);
    }

}
