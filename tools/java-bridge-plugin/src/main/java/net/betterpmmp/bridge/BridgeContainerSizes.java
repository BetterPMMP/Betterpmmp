/*
 * BetterPMMP-Bridge - ViaProxy plugin for BetterPMMP's Java bridge.
 * GPL-3.0-or-later (extends ViaProxy/ViaBedrock, which are GPL-3.0).
 */
package net.betterpmmp.bridge;

import com.viaversion.viaversion.api.connection.StorableObject;

import java.util.HashMap;
import java.util.Map;

/** Container sizes announced by BetterPMMP right before it opens a window (window id -> slot count). */
public final class BridgeContainerSizes implements StorableObject {

    private final Map<Integer, Integer> sizes = new HashMap<>();

    public void put(final int windowId, final int size) {
        this.sizes.put(windowId, size);
    }

    public int take(final int windowId, final int fallback) {
        final Integer size = this.sizes.remove(windowId);
        return size == null ? fallback : size;
    }

}
