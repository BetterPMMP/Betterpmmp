/*
 * BetterPMMP-Bridge - ViaProxy plugin for BetterPMMP's Java bridge.
 * GPL-3.0-or-later (extends ViaProxy/ViaBedrock, which are GPL-3.0).
 */
package net.betterpmmp.bridge;

import com.viaversion.viaversion.api.connection.UserConnection;
import com.viaversion.viaversion.api.minecraft.BlockPosition;
import com.viaversion.viaversion.api.minecraft.item.Item;
import com.viaversion.viaversion.libs.mcstructs.text.TextComponent;
import net.raphimc.viabedrock.api.model.container.Container;
import net.raphimc.viabedrock.api.model.container.player.HudContainer;
import net.raphimc.viabedrock.protocol.data.enums.bedrock.generated.ContainerType;
import net.raphimc.viabedrock.protocol.storage.InventoryTracker;

/**
 * A crafting table as Java crafting screen: slot 0 is the result, 1-9 the grid.
 * Bedrock keeps the grid in the player's UI container (slots 32-40) and lets the
 * client compute the result; BetterPMMP sends the result as UI slot 50.
 */
public final class BridgeWorkbenchContainer extends Container {

    public static final int GRID_START = 32;
    public static final int RESULT_SLOT = 50;

    public BridgeWorkbenchContainer(final UserConnection user, final byte containerId, final TextComponent title, final BlockPosition position) {
        super(user, containerId, ContainerType.WORKBENCH, title, position, 10);
    }

    @Override
    public Item[] getJavaItems() {
        final HudContainer hud = this.user.get(InventoryTracker.class).getHudContainer();
        final Item[] items = new Item[10];
        items[0] = hud.getJavaItem(RESULT_SLOT);
        for (int i = 0; i < 9; i++) {
            items[i + 1] = hud.getJavaItem(GRID_START + i);
        }
        return items;
    }

    @Override
    public boolean isValidBlockTag(final String tag) {
        return true;
    }

}
