/*
 * BetterPMMP-Bridge - ViaProxy plugin for BetterPMMP's Java bridge.
 * GPL-3.0-or-later (extends ViaProxy/ViaBedrock, which are GPL-3.0).
 */
package net.betterpmmp.bridge;

import com.viaversion.viaversion.api.connection.UserConnection;
import com.viaversion.viaversion.api.minecraft.BlockPosition;
import com.viaversion.viaversion.libs.mcstructs.text.TextComponent;
import net.raphimc.viabedrock.api.model.container.ChestContainer;

/**
 * A chest window of any size from 9 to 54 slots. Menu plugins open chests at
 * positions without a real chest block (the Bedrock client does not care), so
 * the block check that would close the window again is skipped.
 */
public final class BridgeChestContainer extends ChestContainer {

    public BridgeChestContainer(final UserConnection user, final byte containerId, final TextComponent title, final BlockPosition position, final int size) {
        super(user, containerId, title, position, size);
    }

    @Override
    public boolean isValidBlockTag(final String tag) {
        return true;
    }

}
