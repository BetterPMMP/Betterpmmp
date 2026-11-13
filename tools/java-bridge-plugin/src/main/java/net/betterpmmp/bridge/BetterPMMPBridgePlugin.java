/*
 * BetterPMMP-Bridge - ViaProxy plugin for BetterPMMP's Java bridge.
 * GPL-3.0-or-later (extends ViaProxy/ViaBedrock, which are GPL-3.0).
 */
package net.betterpmmp.bridge;

import com.viaversion.nbt.tag.StringTag;
import com.viaversion.viaversion.api.Via;
import com.viaversion.viaversion.api.minecraft.BlockPosition;
import com.viaversion.viaversion.api.protocol.packet.PacketWrapper;
import com.viaversion.viaversion.api.type.Types;
import com.viaversion.viaversion.api.type.types.version.VersionedTypes;
import com.viaversion.viaversion.libs.mcstructs.text.TextComponent;
import com.viaversion.viaversion.libs.mcstructs.text.components.TranslationComponent;
import com.viaversion.viaversion.protocols.v26_2to26_3.packet.ClientboundPackets26_3;
import com.viaversion.viaversion.protocols.v26_2to26_3.packet.ServerboundPackets26_3;
import net.raphimc.viabedrock.ViaBedrock;
import net.raphimc.viabedrock.api.chunk.BedrockBlockEntity;
import net.raphimc.viabedrock.api.model.container.Container;
import net.raphimc.viabedrock.api.model.container.player.InventoryContainer;
import net.raphimc.viabedrock.api.util.PacketFactory;
import net.raphimc.viabedrock.api.util.TextUtil;
import net.raphimc.viabedrock.protocol.BedrockProtocol;
import net.raphimc.viabedrock.protocol.ClientboundBedrockPackets;
import net.raphimc.viabedrock.protocol.ServerboundBedrockPackets;
import net.raphimc.viabedrock.protocol.data.enums.bedrock.generated.ContainerType;
import net.raphimc.viabedrock.protocol.data.enums.java.generated.ContainerInput;
import com.viaversion.viaversion.api.minecraft.entitydata.EntityData;
import net.raphimc.viabedrock.api.model.entity.Entity;
import net.raphimc.viabedrock.api.model.entity.PlayerEntity;
import net.raphimc.viabedrock.protocol.data.generated.java.EntityDataFields;
import net.raphimc.viabedrock.protocol.storage.ChunkTracker;
import net.raphimc.viabedrock.protocol.storage.EntityTracker;
import net.raphimc.viabedrock.protocol.storage.InventoryTracker;
import net.raphimc.viabedrock.protocol.storage.ResourcePackStorage;
import net.raphimc.viabedrock.protocol.types.BedrockTypes;
import net.raphimc.viaproxy.ViaProxy;
import net.raphimc.viaproxy.plugins.ViaProxyPlugin;
import net.raphimc.viaproxy.plugins.events.ViaProxyLoadedEvent;

import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.CompletableFuture;
import java.util.logging.Level;

/**
 * Fills gaps of ViaBedrock for BetterPMMP servers:
 * <ul>
 *     <li>chest windows of every size (BetterPMMP announces 54-slot menus before opening them),</li>
 *     <li>no block check for chest menus (menu plugins open them without a real chest block),</li>
 *     <li>screen titles shown as the heading of an open chest window (Java hides titles behind open screens),</li>
 *     <li>entity name tags (holograms, NPC names), which ViaBedrock does not translate,</li>
 *     <li>inventory clicks: ViaBedrock cannot translate them yet, so they are forwarded to BetterPMMP
 *     ("betterpmmp:java_click"), which performs the click on the server like vanilla Java does.</li>
 * </ul>
 */
public final class BetterPMMPBridgePlugin extends ViaProxyPlugin {

    public static final String CLICK_MESSAGE = "betterpmmp:java_click";
    public static final String SIZE_MESSAGE = "betterpmmp:container_size";
    public static final String TITLE_MESSAGE = "betterpmmp:window_title";
    public static final String CRAFT_RESULT_MESSAGE = "betterpmmp:craft_result";
    public static final String ENTITY_NAME_MESSAGE = "betterpmmp:entity_name";
    public static final String HIDE_NAMETAG_MESSAGE = "betterpmmp:hide_nametag";
    public static final String CREATIVE_MESSAGE = "betterpmmp:java_creative";
    public static final String LIGHT_MESSAGE = "betterpmmp:light";

    @Override
    public void onEnable() {
        ViaProxy.EVENT_MANAGER.registerRunnable(this::scheduleInstall, ViaProxyLoadedEvent.class);
    }

    /** ViaBedrock registers its packet handlers once its mappings are loaded (asynchronously); replace them after that. */
    private void scheduleInstall() {
        final CompletableFuture<Void> future = Via.getManager().getProtocolManager().getMappingLoaderFuture(BedrockProtocol.class);
        if (future == null) {
            this.install();
        } else {
            future.whenComplete((result, error) -> {
                if (error != null) {
                    ViaBedrock.getPlatform().getLogger().log(Level.WARNING, "[BetterPMMP-Bridge] ViaBedrock failed to load", error);
                    return;
                }
                this.install();
            });
        }
    }

    private void install() {
        final BedrockProtocol protocol = Via.getManager().getProtocolManager().getProtocol(BedrockProtocol.class);
        if (protocol == null) {
            ViaBedrock.getPlatform().getLogger().log(Level.WARNING, "[BetterPMMP-Bridge] ViaBedrock protocol not found");
            return;
        }

        protocol.replaceClientbound(ClientboundBedrockPackets.SCRIPT_MESSAGE, wrapper -> {
            wrapper.cancel();
            final String id = wrapper.read(BedrockTypes.STRING);
            final String value = wrapper.read(BedrockTypes.STRING);
            if (ENTITY_NAME_MESSAGE.equals(id)) {
                // ViaBedrock does not translate name tags: set the Java custom name ourselves
                final String[] parts = value.split(":", 3);
                if (parts.length != 3) {
                    return;
                }
                final long runtimeId;
                try {
                    runtimeId = Long.parseLong(parts[0]);
                } catch (NumberFormatException e) {
                    return;
                }
                final Entity entity = wrapper.user().get(EntityTracker.class).getEntityByRid(runtimeId);
                if (entity == null || entity instanceof PlayerEntity) {
                    return;
                }
                final List<EntityData> data = new ArrayList<>();
                try {
                    final String text = wrapper.user().get(ResourcePackStorage.class).getTexts().translate(parts[2]);
                    data.add(new EntityData(entity.getJavaEntityDataIndex(EntityDataFields.CUSTOM_NAME), VersionedTypes.V26_3.entityDataTypes.optionalComponentType, text.isEmpty() ? null : TextUtil.stringToNbt(text)));
                    data.add(new EntityData(entity.getJavaEntityDataIndex(EntityDataFields.CUSTOM_NAME_VISIBLE), VersionedTypes.V26_3.entityDataTypes.booleanType, !text.isEmpty() && parts[1].equals("1")));
                } catch (RuntimeException e) {
                    return; // this Java entity type has no name fields
                }
                final PacketWrapper setEntityData = PacketWrapper.create(ClientboundPackets26_3.SET_ENTITY_DATA, wrapper.user());
                setEntityData.write(Types.VAR_INT, entity.javaId()); // entity id
                setEntityData.write(VersionedTypes.V26_3.entityDataList, data); // entity data
                setEntityData.send(BedrockProtocol.class);
                return;
            }
            if (LIGHT_MESSAGE.equals(id)) {
                try {
                    BridgeLight.handle(wrapper.user(), value);
                } catch (final Exception e) {
                    ViaBedrock.getPlatform().getLogger().log(Level.WARNING, "[BetterPMMP-Bridge] bad light data", e);
                }
                return;
            }
            if (HIDE_NAMETAG_MESSAGE.equals(id)) {
                // a Bedrock player with a custom name tag (rank, clan): ViaBedrock shows players through one team per
                // player (name in the prefix). Make that team's name tag invisible (the prefix stays for the tab
                // list); BetterPMMP shows the whole name tag above the player instead.
                final long runtimeId;
                try {
                    runtimeId = Long.parseLong(value);
                } catch (NumberFormatException e) {
                    return;
                }
                final Entity entity = wrapper.user().get(EntityTracker.class).getEntityByRid(runtimeId);
                if (!(entity instanceof PlayerEntity player)) {
                    return;
                }
                final PacketWrapper team = PacketWrapper.create(ClientboundPackets26_3.SET_PLAYER_TEAM, wrapper.user());
                team.write(Types.STRING, "vb_" + player.javaId()); // ViaBedrock's team of this player (PlayerEntity.createTeam)
                team.write(Types.BYTE, (byte) 2); // change parameters
                team.write(Types.TAG, TextUtil.stringToNbt("vb_" + player.javaId())); // display name
                team.write(Types.TAG, TextUtil.stringToNbt(player.name() == null ? "" : player.name())); // prefix (tab list)
                team.write(Types.TAG, TextUtil.stringToNbt("")); // suffix
                team.write(Types.VAR_INT, 1); // name tag visibility: never
                team.write(Types.VAR_INT, 1); // collision: never (as ViaBedrock)
                team.write(Types.BOOL_OPTIONAL_VAR_INT, null); // color
                team.write(Types.BYTE, (byte) 3); // friendly flags (as ViaBedrock)
                team.send(BedrockProtocol.class);
                return;
            }
            if (CRAFT_RESULT_MESSAGE.equals(id)) {
                final int windowId;
                try {
                    windowId = Integer.parseInt(value);
                } catch (NumberFormatException e) {
                    return;
                }
                final InventoryTracker inventoryTracker = wrapper.user().get(InventoryTracker.class);
                if (windowId == 0) {
                    // inventory screen: slot 0 is the result of the 2x2 grid
                    final PacketWrapper setSlot = PacketWrapper.create(ClientboundPackets26_3.CONTAINER_SET_SLOT, wrapper.user());
                    setSlot.write(Types.VAR_INT, 0); // container id
                    setSlot.write(Types.VAR_INT, 0); // revision
                    setSlot.write(Types.SHORT, (short) 0); // slot
                    setSlot.write(VersionedTypes.V26_3.item, inventoryTracker.getHudContainer().getJavaItem(BridgeWorkbenchContainer.RESULT_SLOT)); // item
                    setSlot.send(BedrockProtocol.class);
                } else if (inventoryTracker.getCurrentContainer() instanceof BridgeWorkbenchContainer container && (container.containerId() & 0xFF) == windowId) {
                    PacketFactory.sendJavaContainerSetContent(wrapper.user(), container);
                }
                return;
            }
            if (TITLE_MESSAGE.equals(id)) {
                final int colon = value.indexOf(':');
                if (colon <= 0) {
                    return;
                }
                final int windowId;
                try {
                    windowId = Integer.parseInt(value.substring(0, colon));
                } catch (NumberFormatException e) {
                    return;
                }
                final InventoryTracker inventoryTracker = wrapper.user().get(InventoryTracker.class);
                if (inventoryTracker.getCurrentContainer() instanceof BridgeChestContainer container && (container.containerId() & 0xFF) == windowId) {
                    final String text = wrapper.user().get(ResourcePackStorage.class).getTexts().translate(value.substring(colon + 1));
                    // re-opening a Java screen with the same id only changes its heading
                    final PacketWrapper openScreen = PacketWrapper.create(ClientboundPackets26_3.OPEN_SCREEN, wrapper.user());
                    openScreen.write(Types.VAR_INT, (int) container.containerId()); // container id
                    openScreen.write(Types.VAR_INT, container.size() / 9 - 1); // type: generic_9x1 ... generic_9x6
                    openScreen.write(Types.TAG, TextUtil.stringToNbt(text)); // title
                    openScreen.send(BedrockProtocol.class);
                    PacketFactory.sendJavaContainerSetContent(wrapper.user(), container);
                }
                return;
            }
            if (SIZE_MESSAGE.equals(id)) {
                final String[] parts = value.split(":", 3);
                if (parts.length >= 2) {
                    try {
                        BridgeContainerSizes sizes = wrapper.user().get(BridgeContainerSizes.class);
                        if (sizes == null) {
                            sizes = new BridgeContainerSizes();
                            wrapper.user().put(sizes);
                        }
                        sizes.put(Integer.parseInt(parts[0]), Integer.parseInt(parts[1]), parts.length == 3 ? parts[2] : "");
                    } catch (NumberFormatException ignored) {
                    }
                }
            }
        });

        protocol.replaceClientbound(ClientboundBedrockPackets.CONTAINER_OPEN, wrapper -> {
            final ChunkTracker chunkTracker = wrapper.user().get(ChunkTracker.class);
            final InventoryTracker inventoryTracker = wrapper.user().get(InventoryTracker.class);
            final byte containerId = wrapper.read(Types.BYTE); // container id
            final ContainerType type = ContainerType.getByValue(wrapper.read(Types.BYTE)); // type
            if (type == null) {
                wrapper.cancel();
                return;
            }
            final BlockPosition position = wrapper.read(BedrockTypes.BLOCK_POSITION); // position
            wrapper.read(BedrockTypes.VAR_LONG); // entity unique id

            if (inventoryTracker.isAnyScreenOpen()) {
                PacketFactory.sendBedrockContainerClose(wrapper.user(), (byte) -1, ContainerType.NONE);
                wrapper.cancel();
                return;
            }

            final BridgeContainerSizes sizes = wrapper.user().get(BridgeContainerSizes.class);
            final BridgeContainerSizes.Announced announced = sizes == null ? null : sizes.take(containerId & 0xFF);
            final int rows = Math.max(1, Math.min(6, ((announced == null ? 27 : announced.size()) + 8) / 9));

            final BedrockBlockEntity blockEntity = chunkTracker.getBlockEntity(position);
            TextComponent title = new TranslationComponent(rows == 6 ? "container.chestDouble" : "container.chest");
            if (announced != null && !announced.name().isEmpty()) {
                title = TextUtil.stringToTextComponent(wrapper.user().get(ResourcePackStorage.class).getTexts().translate(announced.name()));
            } else if (blockEntity != null && blockEntity.tag().get("CustomName") instanceof StringTag customNameTag) {
                title = TextUtil.stringToTextComponent(wrapper.user().get(ResourcePackStorage.class).getTexts().translate(customNameTag.getValue()));
            }

            final Container container;
            switch (type) {
                case INVENTORY -> {
                    inventoryTracker.setCurrentContainer(new InventoryContainer(wrapper.user(), containerId, position, inventoryTracker.getInventoryContainer()));
                    wrapper.cancel();
                    return;
                }
                case CONTAINER -> container = new BridgeChestContainer(wrapper.user(), containerId, title, position, rows * 9);
                case WORKBENCH -> {
                    final BridgeWorkbenchContainer workbench = new BridgeWorkbenchContainer(wrapper.user(), containerId, new TranslationComponent("container.crafting"), position);
                    inventoryTracker.setCurrentContainer(workbench);
                    wrapper.write(Types.VAR_INT, (int) containerId); // container id
                    wrapper.write(Types.VAR_INT, BedrockProtocol.MAPPINGS.getBedrockToJavaContainers().get(ContainerType.WORKBENCH)); // type: crafting
                    wrapper.write(Types.TAG, TextUtil.textComponentToNbt(workbench.title())); // title
                    return;
                }
                case NONE, CAULDRON, JUKEBOX, ARMOR, HAND, HUD, DECORATED_POT -> {
                    wrapper.cancel();
                    return;
                }
                default -> {
                    wrapper.cancel();
                    ViaBedrock.getPlatform().getLogger().log(Level.WARNING, "Tried to open unimplemented container: " + type);
                    PacketFactory.sendBedrockContainerClose(wrapper.user(), containerId, ContainerType.NONE);
                    return;
                }
            }
            inventoryTracker.setCurrentContainer(container);

            wrapper.write(Types.VAR_INT, (int) containerId); // container id
            wrapper.write(Types.VAR_INT, rows - 1); // type: generic_9x1 ... generic_9x6
            wrapper.write(Types.TAG, TextUtil.textComponentToNbt(title)); // title
        });

        protocol.replaceServerbound(ServerboundPackets26_3.SET_CREATIVE_MODE_SLOT, wrapper -> {
            // ViaBedrock drops creative inventory actions; BetterPMMP sets the slot itself
            wrapper.cancel();
            final short slot = wrapper.read(Types.SHORT);
            final com.viaversion.viaversion.api.minecraft.item.Item javaItem = wrapper.read(VersionedTypes.V26_3.lengthPrefixedItem);
            String name = "";
            int count = 0;
            try {
                if (javaItem != null && javaItem.identifier() != 0 && javaItem.amount() > 0) {
                    final String found = BridgeCreativeItems.bedrockName(wrapper.user(), javaItem.identifier());
                    if (found != null) {
                        name = found;
                        count = javaItem.amount();
                    }
                }
            } catch (final Throwable t) {
                ViaBedrock.getPlatform().getLogger().log(Level.WARNING, "[BetterPMMP-Bridge] creative item could not be translated", t);
                return;
            }
            final int meta = 0;
            final PacketWrapper message = PacketWrapper.create(ServerboundBedrockPackets.SCRIPT_MESSAGE, wrapper.user());
            message.write(BedrockTypes.STRING, CREATIVE_MESSAGE);
            message.write(BedrockTypes.STRING, slot + ";" + name + ";" + meta + ";" + count);
            message.sendToServer(BedrockProtocol.class);
        });

        protocol.replaceServerbound(ServerboundPackets26_3.CONTAINER_CLICK, wrapper -> {
            wrapper.cancel();
            final int containerId = wrapper.read(Types.VAR_INT); // container id
            wrapper.read(Types.VAR_INT); // revision
            final short slot = wrapper.read(Types.SHORT); // slot
            final byte button = wrapper.read(Types.BYTE); // button
            final int rawAction = wrapper.read(Types.VAR_INT); // action

            final InventoryTracker inventoryTracker = wrapper.user().get(InventoryTracker.class);
            if (inventoryTracker.getPendingCloseContainer() != null) {
                return;
            }
            final ContainerInput[] actions = ContainerInput.values();
            final String action = rawAction >= 0 && rawAction < actions.length ? actions[rawAction].name() : "UNKNOWN";

            final PacketWrapper message = PacketWrapper.create(ServerboundBedrockPackets.SCRIPT_MESSAGE, wrapper.user());
            message.write(BedrockTypes.STRING, CLICK_MESSAGE);
            message.write(BedrockTypes.STRING, containerId + ";" + slot + ";" + button + ";" + action);
            message.sendToServer(BedrockProtocol.class);
        });

        BridgePlacementAcks.install(protocol);
        BridgeEntityGuard.install(protocol);

        ViaBedrock.getPlatform().getLogger().log(Level.INFO, "[BetterPMMP-Bridge] chest menus and inventory clicks enabled");
    }

}
