/*
 * BetterPMMP-Bridge - ViaProxy plugin for BetterPMMP's Java bridge.
 * GPL-3.0-or-later (extends ViaProxy/ViaBedrock, which are GPL-3.0).
 */
package net.betterpmmp.bridge;

import com.viaversion.nbt.tag.CompoundTag;
import com.viaversion.nbt.tag.ListTag;
import com.viaversion.nbt.tag.NumberTag;
import com.viaversion.nbt.tag.StringTag;
import com.viaversion.nbt.tag.Tag;
import com.viaversion.viaversion.api.connection.UserConnection;
import com.viaversion.viaversion.api.minecraft.BlockPosition;
import com.viaversion.viaversion.api.minecraft.item.Item;
import com.viaversion.viaversion.api.minecraft.item.StructuredItem;
import com.viaversion.viaversion.api.protocol.packet.PacketWrapper;
import com.viaversion.viaversion.api.type.Types;
import com.viaversion.viaversion.api.type.types.version.VersionedTypes;
import com.viaversion.viaversion.libs.mcstructs.text.TextComponent;
import com.viaversion.viaversion.protocols.v26_2to26_3.packet.ClientboundPackets26_3;
import net.raphimc.viabedrock.api.model.container.Container;
import net.raphimc.viabedrock.api.model.container.player.HudContainer;
import net.raphimc.viabedrock.protocol.BedrockProtocol;
import net.raphimc.viabedrock.protocol.data.enums.bedrock.generated.ContainerType;
import net.raphimc.viabedrock.protocol.model.BedrockItem;
import net.raphimc.viabedrock.protocol.rewriter.ItemRewriter;
import net.raphimc.viabedrock.protocol.storage.InventoryTracker;

import java.util.ArrayList;
import java.util.List;

/**
 * The villager trade screen. Bedrock opens it with an UpdateTrade packet that ViaBedrock drops; this
 * container turns it into the Java merchant screen and its offers.
 * <pre>
 * Java slot   0 payment 1, 1 payment 2, 2 result
 * Bedrock UI  4,           5,           50 (result computed by BetterPMMP)
 * </pre>
 */
public final class BridgeMerchantContainer extends Container {

    private static final int[] HUD_SLOTS = {4, 5, BridgeWorkbenchContainer.RESULT_SLOT};

    /** Bedrock "netId" of each offer, in the order shown on the Java screen. */
    private final List<Integer> netIds = new ArrayList<>();

    public BridgeMerchantContainer(final UserConnection user, final byte containerId, final TextComponent title) {
        super(user, containerId, ContainerType.TRADE, title, new BlockPosition(0, 0, 0), HUD_SLOTS.length);
    }

    /** Bedrock id of the offer at this position of the Java list, or -1. */
    public int netId(final int index) {
        return index >= 0 && index < this.netIds.size() ? this.netIds.get(index) : -1;
    }

    /** Sends the offers of the Bedrock trade NBT ("Recipes") as Java merchant offers. */
    public void sendOffers(final CompoundTag root, final int tradeTier) {
        final ItemRewriter rewriter = this.user.get(ItemRewriter.class);
        final List<CompoundTag> recipes = new ArrayList<>();
        if (root != null && root.get("Recipes") instanceof ListTag<?> list) {
            for (final Tag tag : list) {
                if (tag instanceof CompoundTag recipe && recipe.get("buyA") instanceof CompoundTag && recipe.get("sell") instanceof CompoundTag) {
                    recipes.add(recipe);
                }
            }
        }
        this.netIds.clear();
        final PacketWrapper offers = PacketWrapper.create(ClientboundPackets26_3.MERCHANT_OFFERS, this.user);
        offers.write(Types.VAR_INT, (int) this.containerId); // container id
        offers.write(Types.VAR_INT, recipes.size()); // offer count
        for (final CompoundTag recipe : recipes) {
            this.netIds.add(intOf(recipe, "netId", this.netIds.size() + 1));
            final Item buyA = javaItem(rewriter, (CompoundTag) recipe.get("buyA"), intOf(recipe, "buyCountA", -1));
            final Item buyB = recipe.get("buyB") instanceof CompoundTag tagB ? javaItem(rewriter, tagB, intOf(recipe, "buyCountB", -1)) : null;
            final Item sell = javaItem(rewriter, (CompoundTag) recipe.get("sell"), -1);
            final int uses = intOf(recipe, "uses", 0);
            final int maxUses = intOf(recipe, "maxUses", 12);
            offers.write(VersionedTypes.V26_3.itemCost, cost(buyA)); // price 1
            offers.write(VersionedTypes.V26_3.item, sell); // result
            offers.write(VersionedTypes.V26_3.optionalItemCost, buyB == null || buyB.isEmpty() ? null : cost(buyB)); // price 2
            offers.write(Types.BOOLEAN, uses >= maxUses); // out of stock
            offers.write(Types.INT, uses); // uses
            offers.write(Types.INT, maxUses); // max uses
            offers.write(Types.INT, intOf(recipe, "traderExp", 1)); // xp
            offers.write(Types.INT, 0); // special price (BetterPMMP already sends the final price)
            offers.write(Types.FLOAT, 0.05F); // price multiplier
            offers.write(Types.INT, 0); // demand
        }
        offers.write(Types.VAR_INT, Math.max(1, Math.min(5, tradeTier + 1))); // villager level (1 = novice)
        offers.write(Types.VAR_INT, root == null ? 0 : intOf(root, "BetterPMMPXp", 0)); // villager experience
        offers.write(Types.BOOLEAN, root != null && root.get("BetterPMMPXp") != null); // show progress bar
        offers.write(Types.BOOLEAN, true); // can restock
        offers.send(BedrockProtocol.class);
    }

    private static int intOf(final CompoundTag tag, final String key, final int fallback) {
        return tag.get(key) instanceof NumberTag number ? number.asInt() : fallback;
    }

    private static Item javaItem(final ItemRewriter rewriter, final CompoundTag nbt, final int count) {
        if (rewriter == null || !(nbt.get("Name") instanceof StringTag name)) {
            return StructuredItem.empty();
        }
        final Integer id = rewriter.getItems().get(name.getValue());
        if (id == null) {
            return StructuredItem.empty();
        }
        final int amount = count > 0 ? count : intOf(nbt, "Count", 1);
        final CompoundTag tag = nbt.get("tag") instanceof CompoundTag t ? t : null;
        try {
            final Item java = rewriter.javaItem(new BedrockItem(id, (short) intOf(nbt, "Damage", 0), (byte) Math.max(1, Math.min(127, amount)), tag));
            if (java != null) {
                java.setAmount(Math.max(1, amount));
                return java;
            }
        } catch (final Throwable ignored) {
        }
        return StructuredItem.empty();
    }

    /** A price only names the item and count; the server decides what is accepted. */
    private static Item cost(final Item item) {
        return item.isEmpty() ? new StructuredItem(1, 1) : new StructuredItem(item.identifier(), item.amount());
    }

    @Override
    public Item[] getJavaItems() {
        final HudContainer hud = this.user.get(InventoryTracker.class).getHudContainer();
        final Item[] items = new Item[HUD_SLOTS.length];
        for (int i = 0; i < HUD_SLOTS.length; i++) {
            items[i] = hud.getJavaItem(HUD_SLOTS[i]);
        }
        return items;
    }

    @Override
    public boolean isValidBlockTag(final String tag) {
        return true;
    }

}
