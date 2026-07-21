import { CircleAlert, RefreshCcw } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Bubble, BubbleContent } from '@/components/ui/bubble';
import { Button } from '@/components/ui/button';
import { Marker, MarkerContent, MarkerIcon } from '@/components/ui/marker';
import {
    Message,
    MessageAvatar,
    MessageContent,
    MessageFooter,
    MessageHeader,
} from '@/components/ui/message';
import { Spinner } from '@/components/ui/spinner';
import { AssistantMessage } from './assistant-message';
import { MessageFeedback } from './conversation-feedback';
import type { Message as ConversationMessage } from './types';

function getInitials(name: string) {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function UserMessageStatus({
    message,
    retryMessage,
    sending,
}: {
    message: ConversationMessage;
    retryMessage: (message: ConversationMessage) => Promise<void>;
    sending: boolean;
}) {
    if (message.role !== 'user' || message.response_status !== 'failed') {
        return null;
    }

    return (
        <MessageFooter>
            <Marker
                role="status"
                className="w-auto justify-end gap-1 text-xs text-destructive"
            >
                <MarkerIcon>
                    <CircleAlert />
                </MarkerIcon>
                <MarkerContent>
                    {message.response_error ?? 'Chef could not respond.'}
                </MarkerContent>
                {message.client_message_id && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        title="Retry"
                        aria-label="Retry message"
                        disabled={sending}
                        onClick={() => void retryMessage(message)}
                    >
                        <RefreshCcw />
                    </Button>
                )}
            </Marker>
        </MessageFooter>
    );
}

export function ConversationMessageRow({
    message,
    retryMessage,
    sending,
    showAvatar,
    showHeader,
}: {
    message: ConversationMessage;
    retryMessage: (message: ConversationMessage) => Promise<void>;
    sending: boolean;
    showAvatar: boolean;
    showHeader: boolean;
}) {
    const authorName =
        message.role === 'user' ? (message.author?.name ?? 'You') : 'Chef';

    return (
        <Message align={message.role === 'user' ? 'end' : 'start'}>
            <MessageAvatar
                className={showAvatar ? undefined : 'bg-transparent'}
            >
                {showAvatar && (
                    <Avatar className="size-8">
                        <AvatarFallback
                            className={
                                message.role === 'assistant'
                                    ? 'bg-primary/10 text-xs font-semibold text-primary'
                                    : 'text-xs font-semibold'
                            }
                        >
                            {getInitials(authorName)}
                        </AvatarFallback>
                    </Avatar>
                )}
            </MessageAvatar>
            <MessageContent>
                {showHeader && <MessageHeader>{authorName}</MessageHeader>}
                {message.role === 'assistant' &&
                message.content === '' &&
                sending ? (
                    <Marker role="status">
                        <MarkerIcon>
                            <Spinner />
                        </MarkerIcon>
                        <MarkerContent className="shimmer">
                            Thinking…
                        </MarkerContent>
                    </Marker>
                ) : (
                    <>
                        <Bubble
                            variant={
                                message.role === 'user' ? 'muted' : 'ghost'
                            }
                            align={message.role === 'user' ? 'end' : 'start'}
                        >
                            <BubbleContent
                                className={
                                    message.role === 'user'
                                        ? 'whitespace-pre-wrap'
                                        : undefined
                                }
                            >
                                {message.role === 'user' ? (
                                    message.content
                                ) : (
                                    <AssistantMessage
                                        content={message.content}
                                    />
                                )}
                            </BubbleContent>
                        </Bubble>
                        {message.role === 'assistant' &&
                            typeof message.id === 'number' &&
                            message.content !== '' && (
                                <MessageFooter>
                                    <MessageFeedback
                                        content={message.content}
                                        messageId={message.id}
                                        feedback={message.feedback?.[0]}
                                    />
                                </MessageFooter>
                            )}
                        <UserMessageStatus
                            message={message}
                            retryMessage={retryMessage}
                            sending={sending}
                        />
                    </>
                )}
            </MessageContent>
        </Message>
    );
}
