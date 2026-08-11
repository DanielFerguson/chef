import { ArrowRight, ArrowUp, ImagePlus, X } from 'lucide-react';
import { useRef, useState } from 'react';
import type { DragEvent, FormEvent } from 'react';
import {
    Attachment,
    AttachmentAction,
    AttachmentActions,
    AttachmentContent,
    AttachmentDescription,
    AttachmentGroup,
    AttachmentMedia,
    AttachmentTitle,
} from '@/components/ui/attachment';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { SelectedPhoto } from './types';

function formatBytes(bytes: number) {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function hasDraggedFiles(event: DragEvent) {
    return event.dataTransfer.types.includes('Files');
}

export function ConversationComposer({
    addPhotos,
    error,
    input,
    onSubmit,
    removePhoto,
    selectedPhotos,
    sendPhase,
    sending,
    setInput,
}: {
    addPhotos: (files: File[]) => void;
    error: string | null;
    input: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    removePhoto: (id: number | string) => void;
    selectedPhotos: SelectedPhoto[];
    sendPhase: 'idle' | 'uploading' | 'replying';
    sending: boolean;
    setInput: (value: string) => void;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const dragDepth = useRef(0);
    const [draggingPhotos, setDraggingPhotos] = useState(false);
    const preparingPhotos = selectedPhotos.some(
        (photo) => photo.state === 'processing',
    );

    const onDragEnter = (event: DragEvent<HTMLFormElement>) => {
        if (!hasDraggedFiles(event)) {
            return;
        }

        event.preventDefault();
        dragDepth.current += 1;
        setDraggingPhotos(true);
    };

    const onDragLeave = (event: DragEvent<HTMLFormElement>) => {
        if (!hasDraggedFiles(event)) {
            return;
        }

        event.preventDefault();
        dragDepth.current = Math.max(0, dragDepth.current - 1);

        if (dragDepth.current === 0) {
            setDraggingPhotos(false);
        }
    };

    const onDrop = (event: DragEvent<HTMLFormElement>) => {
        event.preventDefault();
        dragDepth.current = 0;
        setDraggingPhotos(false);
        addPhotos(Array.from(event.dataTransfer.files));
    };

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit(event);
            }}
            onDragEnter={onDragEnter}
            onDragOver={(event) => {
                if (hasDraggedFiles(event)) {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'copy';
                }
            }}
            onDragLeave={onDragLeave}
            onDrop={onDrop}
            className={cn(
                'relative z-10 mx-5 mb-4 shrink-0 rounded-2xl border bg-card p-3 shadow-lg transition-colors sm:mx-8',
                draggingPhotos &&
                    'border-dashed border-primary bg-primary/5 ring-2 ring-primary/20',
            )}
            data-drop-active={draggingPhotos || undefined}
        >
            {draggingPhotos && (
                <div
                    className="pointer-events-none absolute inset-2 z-30 flex items-center justify-center rounded-xl border-2 border-dashed border-primary bg-background/90 text-sm font-medium text-primary backdrop-blur-sm"
                    role="status"
                >
                    <ImagePlus className="mr-2 size-4" />
                    Drop photos to add them
                </div>
            )}

            {selectedPhotos.length > 0 && (
                <AttachmentGroup
                    className="max-w-full px-1 pb-2"
                    aria-label="Photos to attach"
                    aria-live="polite"
                >
                    {selectedPhotos.map((photo, index) => (
                        <Attachment
                            key={photo.id}
                            state={photo.state ?? 'idle'}
                            size="sm"
                            className="max-w-56"
                        >
                            <AttachmentMedia
                                variant={photo.preview_url ? 'image' : 'icon'}
                            >
                                {photo.preview_url ? (
                                    <img
                                        src={photo.preview_url}
                                        alt=""
                                        className="size-full object-cover"
                                    />
                                ) : (
                                    <ImagePlus />
                                )}
                            </AttachmentMedia>
                            <AttachmentContent>
                                <AttachmentTitle>
                                    Photo {index + 1}
                                </AttachmentTitle>
                                <AttachmentDescription>
                                    {photo.state === 'processing'
                                        ? 'Preparing photo preview…'
                                        : (photo.preview_error ??
                                          formatBytes(photo.size_bytes))}
                                </AttachmentDescription>
                            </AttachmentContent>
                            <AttachmentActions>
                                <AttachmentAction
                                    type="button"
                                    aria-label={`Remove photo ${index + 1}`}
                                    onClick={() => removePhoto(photo.id)}
                                >
                                    <X />
                                </AttachmentAction>
                            </AttachmentActions>
                        </Attachment>
                    ))}
                </AttachmentGroup>
            )}

            <textarea
                value={input}
                onChange={(event) => setInput(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        event.currentTarget.form?.requestSubmit();
                    }
                }}
                aria-label="Message Chef"
                placeholder="Tell Chef what the plan should account for…"
                className="min-h-20 w-full resize-none bg-transparent px-2 py-1 text-sm outline-none placeholder:text-muted-foreground"
            />
            {error && (
                <p role="alert" className="px-2 pb-2 text-xs text-destructive">
                    {error}
                </p>
            )}
            <div className="flex items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-1">
                    <input
                        ref={inputRef}
                        type="file"
                        aria-label="Choose photos"
                        accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif"
                        multiple
                        className="sr-only"
                        tabIndex={-1}
                        onChange={(event) => {
                            addPhotos(Array.from(event.target.files ?? []));
                            event.target.value = '';
                        }}
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={sending || selectedPhotos.length >= 4}
                        onClick={() => inputRef.current?.click()}
                    >
                        <ImagePlus />
                        Add photos
                    </Button>
                    <p
                        className="hidden truncate px-1 text-xs text-muted-foreground sm:block"
                        aria-live="polite"
                    >
                        {sendPhase === 'uploading'
                            ? 'Uploading photos…'
                            : sendPhase === 'replying'
                              ? 'Chef is replying'
                              : preparingPhotos
                                ? 'Preparing photo preview…'
                                : 'Shift + Enter for a new line'}
                    </p>
                </div>
                <Button
                    size="icon"
                    type="submit"
                    data-testid="send-message"
                    disabled={
                        sending ||
                        preparingPhotos ||
                        (input.trim() === '' && selectedPhotos.length === 0)
                    }
                >
                    {sending ? (
                        <ArrowRight className="animate-pulse" />
                    ) : (
                        <ArrowUp />
                    )}
                    <span className="sr-only">Send message</span>
                </Button>
            </div>
        </form>
    );
}
