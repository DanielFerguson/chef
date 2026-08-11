<?php

namespace App\Models;

use Database\Factories\PendingMessageAttachmentDeletionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $disk
 * @property string $path
 */
#[Fillable(['disk', 'path'])]
#[Hidden(['disk', 'path'])]
class PendingMessageAttachmentDeletion extends Model
{
    /** @use HasFactory<PendingMessageAttachmentDeletionFactory> */
    use HasFactory;
}
