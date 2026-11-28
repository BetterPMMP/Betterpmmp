/*
 * BetterPMMP-Bridge - ViaProxy plugin for BetterPMMP's Java bridge.
 * GPL-3.0-or-later (extends ViaProxy/ViaBedrock, which are GPL-3.0).
 */
package net.betterpmmp.bridge;

import com.viaversion.viaversion.api.Via;
import com.viaversion.viaversion.api.connection.StorableObject;
import com.viaversion.viaversion.api.connection.UserConnection;
import com.viaversion.viaversion.api.minecraft.HolderSet;
import com.viaversion.viaversion.api.minecraft.item.Item;
import com.viaversion.viaversion.api.protocol.packet.PacketWrapper;
import com.viaversion.viaversion.api.type.Types;
import com.viaversion.viaversion.protocols.v26_2to26_3.Protocol26_2To26_3;
import com.viaversion.viaversion.protocols.v26_2to26_3.packet.ClientboundPackets26_3;
import net.raphimc.viabedrock.protocol.BedrockProtocol;
import net.raphimc.viabedrock.protocol.model.BedrockItem;
import net.raphimc.viabedrock.protocol.rewriter.ItemRewriter;

import java.util.ArrayList;
import java.util.List;

/**
 * Stonecutter recipes for the Java screen. ViaBedrock sends Java no recipes at all; BetterPMMP sends its
 * stonecutter recipes ("index|input|meta|result|meta|count" per line), the bridge turns them into the Java
 * recipe list and maps the button a Java player clicks back to BetterPMMP's recipe index.
 */
public final class BridgeStonecutterRecipes implements StorableObject {

    private record Recipe(int serverIndex, int input, int result) {
    }

    private final List<Recipe> recipes = new ArrayList<>();

    /** Reads the list from BetterPMMP and sends it to the Java client. */
    public void load(final UserConnection user, final String list) {
        this.recipes.clear();
        final ItemRewriter rewriter = user.get(ItemRewriter.class);
        if (rewriter == null) {
            return;
        }
        for (final String line : list.split("\n")) {
            final String[] p = line.split("\\|");
            if (p.length != 6) {
                continue;
            }
            try {
                final int input = javaId(rewriter, p[1], Integer.parseInt(p[2]));
                final int result = javaId(rewriter, p[3], Integer.parseInt(p[4]));
                if (input > 0 && result > 0) {
                    this.recipes.add(new Recipe(Integer.parseInt(p[0]), input, result));
                }
            } catch (final RuntimeException ignored) {
            }
        }
        int itemDisplay = 2; // minecraft:item in the slot display registry (empty, any_fuel, item, ...)
        try {
            final int mapped = Via.getManager().getProtocolManager().getProtocol(Protocol26_2To26_3.class).getMappingData().getSlotDisplayMappings().mappedId("item");
            if (mapped >= 0) {
                itemDisplay = mapped;
            }
        } catch (final RuntimeException ignored) {
        }
        final PacketWrapper packet = PacketWrapper.create(ClientboundPackets26_3.UPDATE_RECIPES, user);
        packet.write(Types.VAR_INT, 0); // property sets
        packet.write(Types.VAR_INT, this.recipes.size()); // stonecutter recipes
        for (final Recipe recipe : this.recipes) {
            packet.write(Types.HOLDER_SET, HolderSet.of(new int[]{recipe.input()})); // input
            packet.write(Types.VAR_INT, itemDisplay); // slot display: item
            packet.write(Types.VAR_INT, recipe.result()); // item
        }
        packet.send(BedrockProtocol.class);
    }

    /** BetterPMMP's recipe index for the n-th recipe the Java screen shows for this input, or -1. */
    public int serverIndex(final int javaInput, final int button) {
        int n = 0;
        for (final Recipe recipe : this.recipes) {
            if (recipe.input() == javaInput) {
                if (n == button) {
                    return recipe.serverIndex();
                }
                n++;
            }
        }
        return -1;
    }

    private static int javaId(final ItemRewriter rewriter, final String name, final int meta) {
        final Integer id = rewriter.getItems().get(name);
        if (id == null) {
            return -1;
        }
        final Item java = rewriter.javaItem(new BedrockItem(id, (short) meta, (byte) 1));
        return java == null || java.isEmpty() ? -1 : java.identifier();
    }

}
