package net.betterpmmp.bridge;

import com.viaversion.viaversion.api.connection.UserConnection;
import com.viaversion.viaversion.api.protocol.packet.PacketWrapper;
import com.viaversion.viaversion.api.type.Types;
import com.viaversion.viaversion.protocols.v26_2to26_3.packet.ClientboundPackets26_3;
import net.raphimc.viabedrock.protocol.BedrockProtocol;

import java.util.Base64;
import java.util.BitSet;

/**
 * Real light for Java players ("betterpmmp:light" from BetterPMMP): ViaBedrock gives Java clients full sky light
 * and no block light everywhere, which shaders show as flat grey. BetterPMMP sends the light it computed for each
 * chunk; this turns it into a Java light update.
 *
 * Message: "chunkX;chunkZ;lowest sub-chunk;sub-chunk count;base64(per sub-chunk 2048 bytes sky + 2048 bytes block)".
 * PocketMine stores nibbles at (x << 8 | z << 4 | y), Java at (y << 8 | z << 4 | x).
 */
final class BridgeLight {

    private static final int ARRAY = 2048;

    private BridgeLight() {
    }

    static void handle(final UserConnection user, final String value) throws Exception {
        final String[] parts = value.split(";", 5);
        if (parts.length != 5) {
            return;
        }
        final int chunkX = Integer.parseInt(parts[0]);
        final int chunkZ = Integer.parseInt(parts[1]);
        final int count = Integer.parseInt(parts[3]);
        final byte[] data = Base64.getDecoder().decode(parts[4]);
        if (count <= 0 || count > 64 || data.length != count * ARRAY * 2) {
            return;
        }
        final byte[][] sky = new byte[count][];
        final byte[][] block = new byte[count][];
        final BitSet skyMask = new BitSet();
        final BitSet blockMask = new BitSet();
        final BitSet emptySky = new BitSet();
        final BitSet emptyBlock = new BitSet();
        int skyCount = 0;
        int blockCount = 0;
        for (int i = 0; i < count; i++) {
            final int section = i + 1; // index 0 is the section below the world
            sky[i] = toJava(data, i * ARRAY * 2);
            block[i] = toJava(data, i * ARRAY * 2 + ARRAY);
            if (isZero(sky[i])) {
                emptySky.set(section);
                sky[i] = null;
            } else {
                skyMask.set(section);
                skyCount++;
            }
            if (isZero(block[i])) {
                emptyBlock.set(section);
                block[i] = null;
            } else {
                blockMask.set(section);
                blockCount++;
            }
        }
        final PacketWrapper packet = PacketWrapper.create(ClientboundPackets26_3.LIGHT_UPDATE, user);
        packet.write(Types.VAR_INT, chunkX);
        packet.write(Types.VAR_INT, chunkZ);
        packet.write(Types.BIT_SET, skyMask);
        packet.write(Types.BIT_SET, blockMask);
        packet.write(Types.BIT_SET, emptySky);
        packet.write(Types.BIT_SET, emptyBlock);
        packet.write(Types.VAR_INT, skyCount);
        for (final byte[] array : sky) {
            if (array != null) {
                packet.write(Types.BYTE_ARRAY_PRIMITIVE, array);
            }
        }
        packet.write(Types.VAR_INT, blockCount);
        for (final byte[] array : block) {
            if (array != null) {
                packet.write(Types.BYTE_ARRAY_PRIMITIVE, array);
            }
        }
        packet.send(BedrockProtocol.class);
    }

    private static boolean isZero(final byte[] array) {
        for (final byte b : array) {
            if (b != 0) {
                return false;
            }
        }
        return true;
    }

    /** PocketMine nibble layout (x, z, y) to Java (y, z, x). */
    static byte[] toJava(final byte[] source, final int offset) {
        final byte[] out = new byte[ARRAY];
        for (int x = 0; x < 16; x++) {
            for (int z = 0; z < 16; z++) {
                for (int y = 0; y < 16; y++) {
                    final int pm = (x << 8) | (z << 4) | y;
                    final int level = (source[offset + (pm >> 1)] >> ((pm & 1) << 2)) & 0x0F;
                    if (level != 0) {
                        final int java = (y << 8) | (z << 4) | x;
                        out[java >> 1] |= (byte) (level << ((java & 1) << 2));
                    }
                }
            }
        }
        return out;
    }
}
