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
 * A smithing table as Java smithing screen: slot 0 template, 1 base item, 2 material, 3 result.
 * Bedrock keeps them in the player's UI container (51 base, 52 material, 53 template); BetterPMMP
 * sends the result as UI slot 50, like for the crafting table.
 */
public final class BridgeSmithingContainer extends Container {

    public BridgeSmithingContainer(final UserConnection user, final byte containerId, final TextComponent title, final BlockPosition position) {
        super(user, containerId, ContainerType.SMITHING_TABLE, title, position, 4);
    }

    @Override
    public Item[] getJavaItems() {
        final HudContainer hud = this.user.get(InventoryTracker.class).getHudContainer();
        return new Item[]{
                hud.getJavaItem(53),
                hud.getJavaItem(51),
                hud.getJavaItem(52),
                hud.getJavaItem(BridgeWorkbenchContainer.RESULT_SLOT),
        };
    }

    @Override
    public boolean isValidBlockTag(final String tag) {
        return true;
    }

}
