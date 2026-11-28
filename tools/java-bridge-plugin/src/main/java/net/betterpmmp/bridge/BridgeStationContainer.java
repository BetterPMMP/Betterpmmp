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
 * Work stations whose input slots Bedrock keeps in the player's UI container: anvil, grindstone,
 * cartography table and enchanting table. The Java screen shows those UI slots and, where the
 * screen has one, the result that BetterPMMP computes and sends as UI slot 50.
 * <pre>
 * Java screen        slots                 Bedrock UI slots
 * anvil              0 input, 1 material, 2 result   1, 2, 50
 * grindstone         0 top, 1 bottom, 2 result       16, 17, 50
 * cartography table  0 map, 1 additional, 2 result   12, 13, 50
 * enchanting table   0 item, 1 lapis                 14, 15
 * stonecutter        0 input, 1 result               3, 50
 * beacon             0 payment                       27
 * loom               0 banner, 1 dye, 2 pattern, 3 result   9, 10, 11, 50
 * </pre>
 */
public final class BridgeStationContainer extends Container {

    private final int[] hudSlots;

    private BridgeStationContainer(final UserConnection user, final byte containerId, final ContainerType type, final TextComponent title, final BlockPosition position, final int[] hudSlots) {
        super(user, containerId, type, title, position, hudSlots.length);
        this.hudSlots = hudSlots;
    }

    /** The station for a Bedrock container type, or null if this class does not handle it. */
    public static BridgeStationContainer create(final UserConnection user, final byte containerId, final ContainerType type, final TextComponent title, final BlockPosition position) {
        final int[] slots = switch (type) {
            case ANVIL -> new int[]{1, 2, BridgeWorkbenchContainer.RESULT_SLOT};
            case GRINDSTONE -> new int[]{16, 17, BridgeWorkbenchContainer.RESULT_SLOT};
            case CARTOGRAPHY -> new int[]{12, 13, BridgeWorkbenchContainer.RESULT_SLOT};
            case ENCHANTMENT -> new int[]{14, 15};
            case STONECUTTER -> new int[]{3, BridgeWorkbenchContainer.RESULT_SLOT};
            case BEACON -> new int[]{27};
            case LOOM -> new int[]{9, 10, 11, BridgeWorkbenchContainer.RESULT_SLOT};
            default -> null;
        };
        return slots == null ? null : new BridgeStationContainer(user, containerId, type, title, position, slots);
    }

    /** Title shown by a vanilla Java server. */
    public static String titleKey(final ContainerType type) {
        return switch (type) {
            case ANVIL -> "container.repair";
            case GRINDSTONE -> "container.grindstone_title";
            case CARTOGRAPHY -> "container.cartography_table";
            case ENCHANTMENT -> "container.enchant";
            case STONECUTTER -> "container.stonecutter";
            case BEACON -> "container.beacon";
            case LOOM -> "container.loom";
            default -> "container.chest";
        };
    }

    @Override
    public Item[] getJavaItems() {
        final HudContainer hud = this.user.get(InventoryTracker.class).getHudContainer();
        final Item[] items = new Item[this.hudSlots.length];
        for (int i = 0; i < this.hudSlots.length; i++) {
            items[i] = hud.getJavaItem(this.hudSlots[i]);
        }
        return items;
    }

    @Override
    public boolean isValidBlockTag(final String tag) {
        return true;
    }

}
