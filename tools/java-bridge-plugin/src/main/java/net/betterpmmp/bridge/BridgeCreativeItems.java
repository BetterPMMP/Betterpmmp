package net.betterpmmp.bridge;

import com.viaversion.viaversion.api.connection.UserConnection;
import com.viaversion.viaversion.api.minecraft.item.Item;
import net.raphimc.viabedrock.protocol.model.BedrockItem;
import net.raphimc.viabedrock.protocol.rewriter.ItemRewriter;

import java.util.HashMap;
import java.util.Map;
import java.util.WeakHashMap;

/**
 * ViaBedrock can only translate Bedrock items to Java ("Java items to Bedrock is not yet supported").
 * For the Java creative inventory the other direction is needed: this builds it once per connection by
 * translating every Bedrock item of the server to Java and remembering which Java item it became.
 */
final class BridgeCreativeItems {

    private static final Map<UserConnection, Map<Integer, String>> CACHE = new WeakHashMap<>();

    private BridgeCreativeItems() {
    }

    static String bedrockName(final UserConnection user, final int javaItemId) {
        Map<Integer, String> map;
        synchronized (CACHE) {
            map = CACHE.get(user);
        }
        if (map == null) {
            map = build(user);
            synchronized (CACHE) {
                CACHE.put(user, map);
            }
        }
        return map.get(javaItemId);
    }

    private static Map<Integer, String> build(final UserConnection user) {
        final Map<Integer, String> map = new HashMap<>();
        final ItemRewriter rewriter = user.get(ItemRewriter.class);
        if (rewriter == null) {
            return map;
        }
        for (final Map.Entry<String, Integer> entry : rewriter.getItems().entrySet()) {
            try {
                final Item java = rewriter.javaItem(new BedrockItem(entry.getValue(), (short) 0, (byte) 1));
                if (java != null && java.identifier() != 0) {
                    // prefer the item whose Bedrock name matches the Java one when several map to the same Java item
                    map.merge(java.identifier(), entry.getKey(), (old, now) -> now.length() < old.length() ? now : old);
                }
            } catch (final Throwable ignored) {
                // items that need block data or are special are skipped
            }
        }
        return map;
    }
}
