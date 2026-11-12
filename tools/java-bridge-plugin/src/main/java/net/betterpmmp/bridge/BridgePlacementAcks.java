package net.betterpmmp.bridge;

import com.viaversion.viaversion.api.connection.UserConnection;
import com.viaversion.viaversion.api.minecraft.BlockPosition;
import com.viaversion.viaversion.api.protocol.AbstractProtocol;
import com.viaversion.viaversion.api.protocol.packet.PacketWrapper;
import com.viaversion.viaversion.api.protocol.packet.State;
import com.viaversion.viaversion.api.protocol.packet.mapping.PacketMapping;
import com.viaversion.viaversion.api.protocol.packet.mapping.PacketMappings;
import com.viaversion.viaversion.api.protocol.remapper.PacketHandler;
import com.viaversion.viaversion.api.type.Types;
import com.viaversion.viaversion.protocols.v26_2to26_3.packet.ServerboundPackets26_3;
import net.raphimc.viabedrock.ViaBedrock;
import net.raphimc.viabedrock.api.util.PacketFactory;
import net.raphimc.viabedrock.protocol.BedrockProtocol;
import net.raphimc.viabedrock.protocol.ClientboundBedrockPackets;
import net.raphimc.viabedrock.protocol.types.BedrockTypes;

import java.lang.reflect.Field;
import java.util.ArrayDeque;
import java.util.Deque;
import java.util.Map;
import java.util.WeakHashMap;
import java.util.concurrent.TimeUnit;
import java.util.logging.Level;

/**
 * Fast building for Java players.
 *
 * ViaBedrock acknowledges a Java block placement (USE_ITEM_ON) to the Java client right away, before the
 * Bedrock server has placed anything. A Java client that gets the acknowledgement puts the blocks it predicted
 * back to the last state it knows from the server - air - so the block it just placed vanishes until the
 * server's block update arrives a moment later. Clicking that block during the gap (speed bridging,
 * double-clicking) clicks air and nothing happens.
 *
 * This holds the acknowledgement back until the server has sent the block update for the clicked block
 * (BetterPMMP always re-sends the clicked block and its neighbours after an interaction), with a short timeout
 * as a safety net.
 */
final class BridgePlacementAcks {

    private static final long TIMEOUT_MS = 600;

    private record Pending(int sequence, BlockPosition clicked, BlockPosition placed) {
    }

    private static BlockPosition side(final BlockPosition pos, final int face) {
        return switch (face) {
            case 0 -> new BlockPosition(pos.x(), pos.y() - 1, pos.z());
            case 1 -> new BlockPosition(pos.x(), pos.y() + 1, pos.z());
            case 2 -> new BlockPosition(pos.x(), pos.y(), pos.z() - 1);
            case 3 -> new BlockPosition(pos.x(), pos.y(), pos.z() + 1);
            case 4 -> new BlockPosition(pos.x() - 1, pos.y(), pos.z());
            case 5 -> new BlockPosition(pos.x() + 1, pos.y(), pos.z());
            default -> pos;
        };
    }

    private static final Map<UserConnection, Deque<Pending>> PENDING = new WeakHashMap<>();

    private BridgePlacementAcks() {
    }

    static void install(final BedrockProtocol protocol) {
        if (!Boolean.parseBoolean(System.getProperty("betterpmmp.fastBuildFix", "true"))) {
            ViaBedrock.getPlatform().getLogger().log(Level.INFO, "[BetterPMMP-Bridge] fast building fix disabled (java-bridge.fast-build-fix: false)");
            return;
        }
        try {
            final PacketMapping useItemOn = mapping(protocol, "serverboundMappings", ServerboundPackets26_3.USE_ITEM_ON.getId());
            final PacketMapping updateBlock = mapping(protocol, "clientboundMappings", ClientboundBedrockPackets.UPDATE_BLOCK.getId());
            if (useItemOn == null || updateBlock == null || useItemOn.handler() == null || updateBlock.handler() == null) {
                ViaBedrock.getPlatform().getLogger().log(Level.WARNING, "[BetterPMMP-Bridge] block placement handlers not found, fast building fix disabled");
                return;
            }
            final PacketHandler originalUse = useItemOn.handler();
            final PacketHandler originalUpdate = updateBlock.handler();

            useItemOn.setHandler(wrapper -> {
                wrapper.passthrough(Types.VAR_INT); // hand
                final BlockPosition clicked = wrapper.passthrough(Types.BLOCK_POSITION1_14);
                final short face = wrapper.passthrough(Types.UNSIGNED_BYTE); // face
                wrapper.passthrough(Types.FLOAT);
                wrapper.passthrough(Types.FLOAT);
                wrapper.passthrough(Types.FLOAT);
                wrapper.passthrough(Types.BOOLEAN); // inside block
                wrapper.passthrough(Types.BOOLEAN); // world border hit
                final int sequence = wrapper.read(Types.VAR_INT);
                wrapper.write(Types.VAR_INT, -1); // ViaBedrock's early acknowledgement now matches no prediction
                wrapper.resetReader();
                remember(wrapper.user(), sequence, clicked, side(clicked, face));
                originalUse.handle(wrapper);
            });

            updateBlock.setHandler(wrapper -> {
                final BlockPosition position = wrapper.passthrough(BedrockTypes.BLOCK_POSITION);
                wrapper.resetReader();
                final int sequence = due(wrapper.user(), position);
                if (sequence >= 0) {
                    // acknowledge right before the server's first block update for the clicked or placed block:
                    // the client drops its prediction and applies the server's block in the same moment, so there is
                    // no gap and the client never resets the player's position
                    PacketFactory.sendJavaBlockChangedAck(wrapper.user(), sequence);
                }
                originalUpdate.handle(wrapper);
            });
            ViaBedrock.getPlatform().getLogger().log(Level.INFO, "[BetterPMMP-Bridge] fast building fix enabled");
        } catch (final Throwable t) {
            ViaBedrock.getPlatform().getLogger().log(Level.WARNING, "[BetterPMMP-Bridge] could not install the fast building fix", t);
        }
    }

    private static PacketMapping mapping(final BedrockProtocol protocol, final String fieldName, final int packetId) throws ReflectiveOperationException {
        final Field field = AbstractProtocol.class.getDeclaredField(fieldName);
        field.setAccessible(true);
        final PacketMappings mappings = (PacketMappings) field.get(protocol);
        return mappings.mappedPacket(State.PLAY, packetId);
    }

    private static void remember(final UserConnection user, final int sequence, final BlockPosition clicked, final BlockPosition placed) {
        synchronized (PENDING) {
            PENDING.computeIfAbsent(user, u -> new ArrayDeque<>()).addLast(new Pending(sequence, clicked, placed));
        }
        user.getChannel().eventLoop().schedule(() -> {
            final int timedOut = expire(user, sequence);
            if (timedOut >= 0) {
                ViaBedrock.getPlatform().getLogger().log(Level.INFO, "[BetterPMMP-Bridge] Bauen: keine Server-Antwort auf Klick bei " + clicked + " innerhalb von " + TIMEOUT_MS + " ms");
                try {
                    PacketFactory.sendJavaBlockChangedAck(user, timedOut);
                } catch (final Throwable ignored) {
                }
            }
        }, TIMEOUT_MS, TimeUnit.MILLISECONDS);
    }

    /**
     * Sequence to acknowledge now that the server sends this block, or -1. Only the oldest waiting click can match:
     * the server answers clicks in order, and a neighbour update of an earlier click must not end a later
     * click's prediction before the server has even seen that click.
     */
    private static int due(final UserConnection user, final BlockPosition position) {
        synchronized (PENDING) {
            final Deque<Pending> queue = PENDING.get(user);
            final Pending head = queue == null ? null : queue.peekFirst();
            if (head == null || !(head.clicked().equals(position) || head.placed().equals(position))) {
                return -1;
            }
            queue.pollFirst();
            return head.sequence();
        }
    }

    /** Removes a click that got no answer in time; returns its sequence if it was still waiting. */
    private static int expire(final UserConnection user, final int sequence) {
        synchronized (PENDING) {
            final Deque<Pending> queue = PENDING.get(user);
            if (queue == null) {
                return -1;
            }
            boolean waiting = false;
            for (final Pending pending : queue) {
                if (pending.sequence() == sequence) {
                    waiting = true;
                    break;
                }
            }
            if (!waiting) {
                return -1;
            }
            while (!queue.isEmpty()) {
                final Pending head = queue.pollFirst();
                if (head.sequence() == sequence) {
                    break;
                }
            }
            return sequence;
        }
    }
}
