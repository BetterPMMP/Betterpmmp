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
import net.raphimc.viabedrock.protocol.data.enums.bedrock.generated.ContainerType;

/**
 * Block containers whose slots are ordinary container slots on both editions: furnaces, hoppers,
 * dispensers, droppers and brewing stands. Only the brewing stand orders its slots differently
 * (Bedrock: ingredient, 3 bottles, fuel - Java: 3 bottles, ingredient, fuel).
 */
public final class BridgeSimpleContainer extends Container {

    private static final int[] BREWING_BEDROCK_TO_JAVA = {3, 0, 1, 2, 4};

    public BridgeSimpleContainer(final UserConnection user, final byte containerId, final ContainerType type, final TextComponent title, final BlockPosition position, final int size) {
        super(user, containerId, type, title, position, size);
    }

    @Override
    public Item[] getJavaItems() {
        final Item[] items = super.getJavaItems();
        if (this.type != ContainerType.BREWING_STAND || items.length != BREWING_BEDROCK_TO_JAVA.length) {
            return items;
        }
        final Item[] ordered = new Item[items.length];
        for (int i = 0; i < items.length; i++) {
            ordered[BREWING_BEDROCK_TO_JAVA[i]] = items[i];
        }
        return ordered;
    }

    @Override
    public int javaSlot(final int slot) {
        if (this.type == ContainerType.BREWING_STAND && slot >= 0 && slot < BREWING_BEDROCK_TO_JAVA.length) {
            return BREWING_BEDROCK_TO_JAVA[slot];
        }
        return slot;
    }

    @Override
    public boolean isValidBlockTag(final String tag) {
        return true;
    }

    /** Slot count of a Bedrock container type handled by this class, or -1. */
    public static int sizeOf(final ContainerType type) {
        return switch (type) {
            case FURNACE, BLAST_FURNACE, SMOKER -> 3;
            case HOPPER, MINECART_HOPPER, BREWING_STAND -> 5;
            case DISPENSER, DROPPER -> 9;
            default -> -1;
        };
    }

    /** Title shown by a vanilla Java server for this kind of block. */
    public static String titleKey(final ContainerType type) {
        return switch (type) {
            case FURNACE -> "container.furnace";
            case BLAST_FURNACE -> "container.blast_furnace";
            case SMOKER -> "container.smoker";
            case HOPPER -> "container.hopper";
            case MINECART_HOPPER -> "entity.minecraft.hopper_minecart";
            case BREWING_STAND -> "container.brewing";
            case DISPENSER -> "container.dispenser";
            case DROPPER -> "container.dropper";
            default -> "container.chest";
        };
    }

}
