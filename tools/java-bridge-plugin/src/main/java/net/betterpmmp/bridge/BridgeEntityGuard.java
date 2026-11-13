package net.betterpmmp.bridge;

import com.viaversion.viaversion.api.protocol.packet.mapping.PacketMapping;
import com.viaversion.viaversion.api.protocol.remapper.PacketHandler;
import com.viaversion.viaversion.protocols.v26_2to26_3.packet.ServerboundPackets26_3;
import net.raphimc.viabedrock.ViaBedrock;
import net.raphimc.viabedrock.protocol.BedrockProtocol;

import java.util.logging.Level;

/**
 * ViaBedrock throws (and the Java player is disconnected) when a Java client attacks or interacts with an entity it
 * no longer tracks - for example one that was just removed, or BetterPMMP's name tag carriers. Such clicks are
 * simply ignored instead.
 */
final class BridgeEntityGuard {

    private BridgeEntityGuard() {
    }

    static void install(final BedrockProtocol protocol) {
        for (final ServerboundPackets26_3 type : new ServerboundPackets26_3[]{ServerboundPackets26_3.ATTACK, ServerboundPackets26_3.INTERACT, ServerboundPackets26_3.PICK_ITEM_FROM_ENTITY}) {
            try {
                final PacketMapping mapping = BridgePlacementAcks.mapping(protocol, "serverboundMappings", type.getId());
                final PacketHandler original = mapping == null ? null : mapping.handler();
                if (original == null) {
                    continue;
                }
                mapping.setHandler(wrapper -> {
                    try {
                        original.handle(wrapper);
                    } catch (final NullPointerException e) {
                        wrapper.cancel(); // unknown entity
                    }
                });
            } catch (final Throwable t) {
                ViaBedrock.getPlatform().getLogger().log(Level.WARNING, "[BetterPMMP-Bridge] could not guard " + type, t);
            }
        }
    }
}
