<?php

namespace App\Models;

use App\Models\Concerns\ResolvesWithinCurrentTeam;
use Database\Factories\MessageAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property int $message_id
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $width
 * @property int $height
 * @property string $source_sha256
 * @property int $position
 */
#[Fillable(['team_id', 'message_id', 'disk', 'path', 'mime_type', 'size_bytes', 'width', 'height', 'source_sha256', 'position'])]
class MessageAttachment extends Model
{
    /** @use HasFactory<MessageAttachmentFactory> */
    use HasFactory;

    use ResolvesWithinCurrentTeam;

    /** @var list<string> */
    protected $hidden = [
        'team_id',
        'message_id',
        'disk',
        'path',
        'source_sha256',
        'created_at',
        'updated_at',
    ];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
