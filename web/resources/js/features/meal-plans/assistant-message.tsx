import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

export function AssistantMessage({ content }: { content: string }) {
    return (
        <div
            className="typeset typeset-docs max-w-[37em]"
            data-message-role="assistant"
        >
            <ReactMarkdown
                remarkPlugins={[remarkGfm]}
                skipHtml
                disallowedElements={['img']}
            >
                {content}
            </ReactMarkdown>
        </div>
    );
}
