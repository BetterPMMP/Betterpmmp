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
import net.raphimc.viabedrock.protocol.storage.ChunkTracker;
import net.raphimc.viabedrock.protocol.storage.InventoryTracker;
import net.raphimc.viabedrock.protocol.storage.ResourcePackStorage;
import net.raphimc.viabedrock.protocol.types.BedrockTypes;
import net.raphimc.viaproxy.ViaProxy;
import net.raphimc.viaproxy.plugins.ViaProxyPlugin;
import net.raphimc.viaproxy.plugins.events.ViaProxyLoadedEvent;

import java.util.concurrent.CompletableFuture;
import java.util.logging.Level;

/**
 * Fills gaps of ViaBedrock for BetterPMMP servers:
 * <ul>
 *     <li>chest windows of every size (BetterPMMP announces 54-slot menus before opening them),</li>
 *     <li>no block check for chest menus (menu plugins open them without a real chest block),</li>
 *     <li>screen titles shown as the heading of an open chest window (Java hides titles behind open screens),</li>
 *     <li>inventory clicks: ViaBedrock cannot translate them yet, so they are forwarded to BetterPMMP
 *     ("betterpmmp:java_click"), which performs the click on the server like vanilla Java does.</li>
 * </ul>
 */
public final class BetterPMMPBridgePlugin extends ViaProxyPlugin {

    public static final String CLICK_MESSAGE = "betterpmmp:java_click";
    public static final String SIZE_MESSAGE = "betterpmmp:container_size";
    public static final String TITLE_MESSAGE = "betterpmmp:window_title";

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

        ViaBedrock.getPlatform().getLogger().log(Level.INFO, "[BetterPMMP-Bridge] chest menus and inventory clicks enabled");
    }

}
