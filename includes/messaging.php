<?php
/**
 * Project Exodia — Messaging & Conversation Service
 *
 * Core business logic for the direct messaging system:
 *  - Creating one-to-one conversations (with duplicate prevention)
 *  - Retrieving user conversation lists with previews
 *  - Sending messages with validation and authorization
 *  - Retrieving conversation history
 *  - Polling for new messages since a given ID
 *  - Authorization checks for conversation access
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Find an existing 1-to-1 conversation between two users, or return null.
 */
function find_direct_conversation(PDO $pdo, int $userA, int $userB): ?int {
    $sql = '
        SELECT cp1.conversation_id
        FROM conversation_participants cp1
        JOIN conversation_participants cp2
          ON cp1.conversation_id = cp2.conversation_id
        WHERE cp1.user_id = :userA
          AND cp2.user_id = :userB
        LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':userA', $userA, PDO::PARAM_INT);
    $stmt->bindValue(':userB', $userB, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ? (int)$row['conversation_id'] : null;
}

/**
 * Create a new 1-to-1 conversation between two users.
 * Returns existing conversation if one already exists.
 */
function create_conversation(PDO $pdo, int $currentUserId, int $otherUserId): array {
    if ($currentUserId <= 0 || $otherUserId <= 0) {
        return ['success' => false, 'error' => 'Invalid user identifier.', 'code' => 'INVALID_ID'];
    }
    if ($currentUserId === $otherUserId) {
        return ['success' => false, 'error' => 'Cannot start a conversation with yourself.', 'code' => 'SELF_CONVERSATION'];
    }

    // Verify the other user exists
    $checkUser = $pdo->prepare('SELECT id, username FROM users WHERE id = :id LIMIT 1');
    $checkUser->bindValue(':id', $otherUserId, PDO::PARAM_INT);
    $checkUser->execute();
    $otherUser = $checkUser->fetch();
    if (!$otherUser) {
        return ['success' => false, 'error' => 'User not found.', 'code' => 'NOT_FOUND'];
    }

    // Check for existing conversation
    $existingId = find_direct_conversation($pdo, $currentUserId, $otherUserId);
    if ($existingId !== null) {
        return [
            'success'         => true,
            'conversation_id' => $existingId,
            'created'         => false,
            'message'         => 'Existing conversation found.',
        ];
    }

    // Create new conversation atomically
    $pdo->beginTransaction();
    try {
        $insertConv = $pdo->prepare('INSERT INTO conversations () VALUES ()');
        $insertConv->execute();
        $convId = (int)$pdo->lastInsertId();

        $insertPart = $pdo->prepare('INSERT INTO conversation_participants (conversation_id, user_id) VALUES (:cid, :uid)');
        $insertPart->bindValue(':cid', $convId, PDO::PARAM_INT);
        $insertPart->bindValue(':uid', $currentUserId, PDO::PARAM_INT);
        $insertPart->execute();

        $insertPart->bindValue(':cid', $convId, PDO::PARAM_INT);
        $insertPart->bindValue(':uid', $otherUserId, PDO::PARAM_INT);
        $insertPart->execute();

        $pdo->commit();

        return [
            'success'         => true,
            'conversation_id' => $convId,
            'created'         => true,
            'message'         => 'Conversation created.',
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Create conversation error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to create conversation.', 'code' => 'DB_ERROR'];
    }
}

/**
 * Check if a user is a participant of a specific conversation.
 */
function is_conversation_participant(PDO $pdo, int $conversationId, int $userId): bool {
    $stmt = $pdo->prepare('SELECT id FROM conversation_participants WHERE conversation_id = :cid AND user_id = :uid LIMIT 1');
    $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->execute();
    return (bool)$stmt->fetch();
}

/**
 * Get the list of conversations for a user, ordered by most recent activity.
 * Includes the other participant's info and the last message preview.
 */
function get_user_conversations(PDO $pdo, int $userId): array {
    $sql = '
        SELECT
            c.id AS conversation_id,
            c.updated_at,
            u.id AS other_user_id,
            u.username AS other_username,
            m.id AS last_message_id,
            m.body AS last_message_body,
            COALESCE(m.is_deleted_for_everyone, 0) AS last_message_is_deleted,
            m.created_at AS last_message_time,
            m.sender_id AS last_message_sender_id
        FROM conversations c
        JOIN conversation_participants cp1 ON cp1.conversation_id = c.id AND cp1.user_id = :uid
        JOIN conversation_participants cp2 ON cp2.conversation_id = c.id AND cp2.user_id != :uid2
        JOIN users u ON u.id = cp2.user_id
        LEFT JOIN messages m ON m.id = (
            SELECT m2.id FROM messages m2
            LEFT JOIN user_hidden_messages uhm ON uhm.message_id = m2.id AND uhm.user_id = :uid3
            WHERE m2.conversation_id = c.id AND uhm.id IS NULL
            ORDER BY m2.id DESC
            LIMIT 1
        )
        ORDER BY COALESCE(m.created_at, c.updated_at) DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':uid2', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':uid3', $userId, PDO::PARAM_INT);
    $stmt->execute();

    $conversations = [];
    while ($row = $stmt->fetch()) {
        if ((int)$row['last_message_is_deleted'] === 1) {
            $preview = 'This message was deleted.';
        } else {
            $preview = $row['last_message_body'];
            if ($preview !== null && mb_strlen($preview) > 80) {
                $preview = mb_substr($preview, 0, 80) . '…';
            }
        }
        $conversations[] = [
            'conversation_id'       => (int)$row['conversation_id'],
            'other_user_id'         => (int)$row['other_user_id'],
            'other_username'        => $row['other_username'],
            'last_message_preview'  => $preview,
            'last_message_time'     => $row['last_message_time'],
            'last_message_sender_id'=> $row['last_message_sender_id'] ? (int)$row['last_message_sender_id'] : null,
            'updated_at'            => $row['updated_at'],
        ];
    }

    return $conversations;
}

/**
 * Supported reaction emojis allowlist.
 */
const ALLOWED_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '😡'];

/**
 * Send a message in a conversation.
 * Supports optional reply_to_message_id for quoted replies.
 */
function send_message(PDO $pdo, int $conversationId, int $senderId, string $body, ?int $replyToMessageId = null): array {
    $body = trim($body);
    if ($body === '') {
        return ['success' => false, 'error' => 'Message cannot be empty.', 'code' => 'EMPTY_MESSAGE'];
    }
    if (mb_strlen($body) > 5000) {
        return ['success' => false, 'error' => 'Message is too long (max 5000 characters).', 'code' => 'TOO_LONG'];
    }

    // Verify sender is a participant
    if (!is_conversation_participant($pdo, $conversationId, $senderId)) {
        return ['success' => false, 'error' => 'You are not a participant of this conversation.', 'code' => 'UNAUTHORIZED'];
    }

    // Validate reply_to_message_id if provided
    $replyIdParam = null;
    if ($replyToMessageId !== null && $replyToMessageId > 0) {
        $checkReply = $pdo->prepare('SELECT id, conversation_id, is_deleted_for_everyone FROM messages WHERE id = :mid LIMIT 1');
        $checkReply->bindValue(':mid', $replyToMessageId, PDO::PARAM_INT);
        $checkReply->execute();
        $targetMsg = $checkReply->fetch(PDO::FETCH_ASSOC);

        if (!$targetMsg || (int)$targetMsg['conversation_id'] !== $conversationId) {
            return ['success' => false, 'error' => 'Referenced message does not exist in this conversation.', 'code' => 'INVALID_REPLY'];
        }

        if ((int)$targetMsg['is_deleted_for_everyone'] === 1) {
            return ['success' => false, 'error' => 'Cannot reply to a deleted message.', 'code' => 'REPLY_TO_DELETED'];
        }

        $replyIdParam = $replyToMessageId;
    }

    $pdo->beginTransaction();
    try {
        $insertMsg = $pdo->prepare('
            INSERT INTO messages (conversation_id, sender_id, body, reply_to_message_id)
            VALUES (:cid, :sid, :body, :reply_id)
        ');
        $insertMsg->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $insertMsg->bindValue(':sid', $senderId, PDO::PARAM_INT);
        $insertMsg->bindValue(':body', $body, PDO::PARAM_STR);
        if ($replyIdParam !== null) {
            $insertMsg->bindValue(':reply_id', $replyIdParam, PDO::PARAM_INT);
        } else {
            $insertMsg->bindValue(':reply_id', null, PDO::PARAM_NULL);
        }
        $insertMsg->execute();
        $messageId = (int)$pdo->lastInsertId();

        // Touch conversation updated_at
        $touchConv = $pdo->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = :cid');
        $touchConv->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $touchConv->execute();

        // Log message_created activity event
        $evPayload = json_encode(['reply_to_message_id' => $replyIdParam]);
        $ev = $pdo->prepare('
            INSERT INTO conversation_events (conversation_id, event_type, message_id, actor_id, payload)
            VALUES (:cid, "message_created", :mid, :aid, :payload)
        ');
        $ev->execute([
            ':cid'     => $conversationId,
            ':mid'     => $messageId,
            ':aid'     => $senderId,
            ':payload' => $evPayload,
        ]);

        $pdo->commit();

        return [
            'success'              => true,
            'message_id'           => $messageId,
            'conversation_id'      => $conversationId,
            'reply_to_message_id'  => $replyIdParam,
            'message'              => 'Message sent.',
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Send message error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to send message.', 'code' => 'DB_ERROR'];
    }
}

/**
 * Get summary of reactions grouped by emoji for a list of messages.
 */
function get_message_reactions_summary(PDO $pdo, array $messageIds, int $currentUserId = 0): array {
    if (empty($messageIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    $sql = "
        SELECT message_id, emoji, user_id
        FROM message_reactions
        WHERE message_id IN ({$placeholders})
        ORDER BY created_at ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($messageIds));

    $grouped = [];
    foreach ($messageIds as $mid) {
        $grouped[(int)$mid] = [];
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int)$row['message_id'];
        $em = $row['emoji'];
        $uid = (int)$row['user_id'];

        if (!isset($grouped[$mid][$em])) {
            $grouped[$mid][$em] = [
                'emoji'       => $em,
                'count'       => 0,
                'has_reacted' => false,
            ];
        }
        $grouped[$mid][$em]['count']++;
        if ($uid === $currentUserId) {
            $grouped[$mid][$em]['has_reacted'] = true;
        }
    }

    $result = [];
    foreach ($grouped as $mid => $emojis) {
        $result[$mid] = array_values($emojis);
    }
    return $result;
}

/**
 * Get message history for a conversation, optionally after a specific message ID.
 * Filters out hidden messages for $currentUserId and masks deleted messages.
 */
function get_messages(PDO $pdo, int $conversationId, int $afterId = 0, int $limit = 100, int $currentUserId = 0): array {
    $limit = max(1, min(200, $limit));

    $joinHidden = '';
    $whereHidden = '';
    if ($currentUserId > 0) {
        $joinHidden = ' LEFT JOIN user_hidden_messages uhm ON uhm.message_id = m.id AND uhm.user_id = :uid ';
        $whereHidden = ' AND uhm.id IS NULL ';
    }

    $afterClause = '';
    if ($afterId > 0) {
        $afterClause = ' AND m.id > :after_id ';
    }

    $sql = '
        SELECT 
            m.id, 
            m.sender_id, 
            m.body, 
            COALESCE(m.is_deleted_for_everyone, 0) AS is_deleted_for_everyone,
            m.deleted_at,
            m.reply_to_message_id,
            m.created_at, 
            u.username AS sender_username,
            rm.id AS reply_id,
            rm.sender_id AS reply_sender_id,
            ru.username AS reply_sender_username,
            rm.body AS reply_body,
            COALESCE(rm.is_deleted_for_everyone, 0) AS reply_is_deleted
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        LEFT JOIN messages rm ON rm.id = m.reply_to_message_id
        LEFT JOIN users ru ON ru.id = rm.sender_id
        ' . $joinHidden . '
        WHERE m.conversation_id = :cid ' . $whereHidden . $afterClause . '
        ORDER BY m.id ASC
        LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
    if ($currentUserId > 0) {
        $stmt->bindValue(':uid', $currentUserId, PDO::PARAM_INT);
    }
    if ($afterId > 0) {
        $stmt->bindValue(':after_id', $afterId, PDO::PARAM_INT);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return [];
    }

    $messageIds = array_map(fn($r) => (int)$r['id'], $rows);
    $reactionsSummary = get_message_reactions_summary($pdo, $messageIds, $currentUserId);

    $messages = [];
    foreach ($rows as $row) {
        $mid = (int)$row['id'];
        $isDeleted = ((int)$row['is_deleted_for_everyone'] === 1);

        $replyTo = null;
        if (!empty($row['reply_id'])) {
            $replyDeleted = ((int)$row['reply_is_deleted'] === 1);
            $replyTo = [
                'id'                      => (int)$row['reply_id'],
                'sender_id'               => (int)$row['reply_sender_id'],
                'sender_username'         => $row['reply_sender_username'] ?? '',
                'body'                    => $replyDeleted ? 'This message was deleted.' : $row['reply_body'],
                'is_deleted_for_everyone' => $replyDeleted,
            ];
        }

        $messages[] = [
            'id'                      => $mid,
            'sender_id'               => (int)$row['sender_id'],
            'sender_username'         => $row['sender_username'],
            'body'                    => $isDeleted ? 'This message was deleted.' : $row['body'],
            'is_deleted_for_everyone' => $isDeleted,
            'deleted_at'              => $row['deleted_at'],
            'reply_to'                => $replyTo,
            'reactions'               => $reactionsSummary[$mid] ?? [],
            'created_at'              => $row['created_at'],
        ];
    }

    return $messages;
}

/**
 * Delete a message for the current user only ("Delete for Me").
 * Hides the message from this user's view, while leaving it visible for others.
 */
function delete_message_for_me(PDO $pdo, int $messageId, int $userId): array {
    if ($messageId <= 0 || $userId <= 0) {
        return ['success' => false, 'error' => 'Invalid message or user identifier.', 'code' => 'INVALID_ID'];
    }

    // Verify message exists and user is a participant of its conversation
    $stmt = $pdo->prepare('
        SELECT m.id, m.conversation_id
        FROM messages m
        JOIN conversation_participants cp ON cp.conversation_id = m.conversation_id AND cp.user_id = :uid
        WHERE m.id = :mid
        LIMIT 1
    ');
    $stmt->execute([':uid' => $userId, ':mid' => $messageId]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$msg) {
        return ['success' => false, 'error' => 'Message not found or unauthorized.', 'code' => 'NOT_FOUND'];
    }

    try {
        $insert = $pdo->prepare('
            INSERT INTO user_hidden_messages (user_id, message_id)
            VALUES (:uid, :mid)
            ON DUPLICATE KEY UPDATE hidden_at = CURRENT_TIMESTAMP
        ');
        $insert->execute([':uid' => $userId, ':mid' => $messageId]);

        return [
            'success'         => true,
            'message_id'      => $messageId,
            'conversation_id' => (int)$msg['conversation_id'],
            'action'          => 'delete_for_me',
            'message'         => 'Message hidden for you.',
        ];
    } catch (Throwable $e) {
        error_log('Delete for me error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to hide message.', 'code' => 'DB_ERROR'];
    }
}

/**
 * Delete a message for all participants ("Delete for Everyone").
 * Only the original sender of the message can perform this action.
 */
function delete_message_for_everyone(PDO $pdo, int $messageId, int $userId): array {
    if ($messageId <= 0 || $userId <= 0) {
        return ['success' => false, 'error' => 'Invalid message or user identifier.', 'code' => 'INVALID_ID'];
    }

    // Fetch message details
    $stmt = $pdo->prepare('SELECT id, conversation_id, sender_id, is_deleted_for_everyone FROM messages WHERE id = :mid LIMIT 1');
    $stmt->execute([':mid' => $messageId]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$msg) {
        return ['success' => false, 'error' => 'Message not found.', 'code' => 'NOT_FOUND'];
    }

    // Verify sender ownership
    if ((int)$msg['sender_id'] !== $userId) {
        return ['success' => false, 'error' => 'You can only delete your own messages for everyone.', 'code' => 'FORBIDDEN'];
    }

    $convId = (int)$msg['conversation_id'];

    if ((int)$msg['is_deleted_for_everyone'] === 1) {
        return [
            'success'                 => true,
            'message_id'              => $messageId,
            'conversation_id'         => $convId,
            'already_deleted'         => true,
            'is_deleted_for_everyone' => true,
            'message'                 => 'Message already deleted.',
        ];
    }

    $pdo->beginTransaction();
    try {
        // Mark message as deleted for everyone and blank out body
        $update = $pdo->prepare('
            UPDATE messages
            SET is_deleted_for_everyone = 1,
                deleted_at = NOW(),
                body = ""
            WHERE id = :mid
        ');
        $update->execute([':mid' => $messageId]);

        // Remove any reactions on this deleted message
        $delReactions = $pdo->prepare('DELETE FROM message_reactions WHERE message_id = :mid');
        $delReactions->execute([':mid' => $messageId]);

        // Record activity event
        $payload = json_encode(['is_deleted_for_everyone' => true, 'placeholder' => 'This message was deleted.']);
        $ev = $pdo->prepare('
            INSERT INTO conversation_events (conversation_id, event_type, message_id, actor_id, payload)
            VALUES (:cid, "message_deleted", :mid, :aid, :payload)
        ');
        $ev->execute([
            ':cid'     => $convId,
            ':mid'     => $messageId,
            ':aid'     => $userId,
            ':payload' => $payload,
        ]);

        $pdo->commit();

        return [
            'success'                 => true,
            'message_id'              => $messageId,
            'conversation_id'         => $convId,
            'action'                  => 'delete_for_everyone',
            'is_deleted_for_everyone' => true,
            'message'                 => 'Message deleted for everyone.',
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Delete for everyone error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to delete message.', 'code' => 'DB_ERROR'];
    }
}

/**
 * Toggle an emoji reaction on a message for the current user.
 * Supports: 👍 ❤️ 😂 😮 😢 😡
 * Clicking same emoji removes it; clicking different changes it.
 */
function toggle_message_reaction(PDO $pdo, int $messageId, int $userId, string $emoji): array {
    if (!in_array($emoji, ALLOWED_REACTIONS, true)) {
        return ['success' => false, 'error' => 'Unsupported reaction emoji.', 'code' => 'INVALID_EMOJI'];
    }

    // Verify message exists and user is participant
    $stmt = $pdo->prepare('
        SELECT m.id, m.conversation_id, m.is_deleted_for_everyone
        FROM messages m
        JOIN conversation_participants cp ON cp.conversation_id = m.conversation_id AND cp.user_id = :uid
        WHERE m.id = :mid
        LIMIT 1
    ');
    $stmt->execute([':uid' => $userId, ':mid' => $messageId]);
    $msg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$msg) {
        return ['success' => false, 'error' => 'Message not found or unauthorized.', 'code' => 'NOT_FOUND'];
    }

    if ((int)$msg['is_deleted_for_everyone'] === 1) {
        return ['success' => false, 'error' => 'Cannot react to a deleted message.', 'code' => 'MESSAGE_DELETED'];
    }

    $convId = (int)$msg['conversation_id'];

    // Check existing reaction by this user on this message
    $check = $pdo->prepare('SELECT id, emoji FROM message_reactions WHERE message_id = :mid AND user_id = :uid LIMIT 1');
    $check->execute([':mid' => $messageId, ':uid' => $userId]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    try {
        if ($existing && $existing['emoji'] === $emoji) {
            // Remove reaction (toggle off)
            $del = $pdo->prepare('DELETE FROM message_reactions WHERE id = :id');
            $del->execute([':id' => $existing['id']]);
            $action = 'removed';
            $activeEmoji = null;
        } elseif ($existing) {
            // Update to new emoji
            $upd = $pdo->prepare('UPDATE message_reactions SET emoji = :emoji, updated_at = NOW() WHERE id = :id');
            $upd->execute([':emoji' => $emoji, ':id' => $existing['id']]);
            $action = 'updated';
            $activeEmoji = $emoji;
        } else {
            // Insert new reaction
            $ins = $pdo->prepare('INSERT INTO message_reactions (message_id, user_id, emoji) VALUES (:mid, :uid, :emoji)');
            $ins->execute([':mid' => $messageId, ':uid' => $userId, ':emoji' => $emoji]);
            $action = 'added';
            $activeEmoji = $emoji;
        }

        // Broadcast reaction event
        $ev = $pdo->prepare('
            INSERT INTO conversation_events (conversation_id, event_type, message_id, actor_id, payload)
            VALUES (:cid, "reaction_changed", :mid, :aid, :payload)
        ');
        $ev->execute([
            ':cid'     => $convId,
            ':mid'     => $messageId,
            ':aid'     => $userId,
            ':payload' => json_encode(['emoji' => $emoji, 'action' => $action]),
        ]);

        $pdo->commit();

        // Return updated reaction summary for this message
        $reactions = get_message_reactions_summary($pdo, [$messageId], $userId)[$messageId] ?? [];

        return [
            'success'       => true,
            'message_id'    => $messageId,
            'action'        => $action,
            'user_reaction' => $activeEmoji,
            'reactions'     => $reactions,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Reaction error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to update reaction.', 'code' => 'DB_ERROR'];
    }
}

/**
 * Get the latest event ID for a conversation.
 */
function get_max_event_id(PDO $pdo, int $conversationId): int {
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM conversation_events WHERE conversation_id = :cid');
    $stmt->execute([':cid' => $conversationId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Get incremental activity events for real-time synchronization.
 */
function get_conversation_events(PDO $pdo, int $conversationId, int $afterEventId, int $currentUserId = 0, int $limit = 100): array {
    $limit = max(1, min(200, $limit));
    $sql = '
        SELECT ce.id, ce.conversation_id, ce.event_type, ce.message_id, ce.actor_id, ce.payload, ce.created_at
        FROM conversation_events ce
        WHERE ce.conversation_id = :cid AND ce.id > :after_event_id
        ORDER BY ce.id ASC
        LIMIT ' . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':cid', $conversationId, PDO::PARAM_INT);
    $stmt->bindValue(':after_event_id', $afterEventId, PDO::PARAM_INT);
    $stmt->execute();

    $events = [];
    $messageIds = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $events[] = [
            'id'              => (int)$row['id'],
            'conversation_id' => (int)$row['conversation_id'],
            'event_type'      => $row['event_type'],
            'message_id'      => (int)$row['message_id'],
            'actor_id'        => (int)$row['actor_id'],
            'payload'         => json_decode($row['payload'] ?? '{}', true),
            'created_at'      => $row['created_at'],
        ];
        $messageIds[] = (int)$row['message_id'];
    }

    if (!empty($messageIds)) {
        $reactionsSummary = get_message_reactions_summary($pdo, array_unique($messageIds), $currentUserId);
        foreach ($events as &$ev) {
            $mid = $ev['message_id'];
            if (isset($reactionsSummary[$mid])) {
                $ev['reactions'] = $reactionsSummary[$mid];
            }
        }
        unset($ev);
    }

    return $events;
}
