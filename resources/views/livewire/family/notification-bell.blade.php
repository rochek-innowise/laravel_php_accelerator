<div wire:poll.30s>
    <details class="relative">
        <summary class="btn-ghost cursor-pointer list-none">
            Notifications
            @if ($unreadCount > 0)
                <span class="ml-1 inline-flex size-5 items-center justify-center rounded-full bg-foul text-xs font-bold text-field">
                    {{ $unreadCount }}
                </span>
            @endif
        </summary>

        <div class="absolute right-0 z-10 mt-2 w-72 rounded-(--radius) border border-line bg-paper p-2 shadow-lg lg:left-0 lg:right-auto">
            @forelse ($unread as $notification)
                <button
                    type="button"
                    wire:click="markAsRead('{{ $notification->id }}')"
                    class="block w-full rounded px-2 py-2 text-left text-sm text-ink hover:bg-field"
                >
                    <span class="block font-medium">{{ $notification->data['child_name'] ?? 'Update' }}</span>
                    <span class="block text-ink-soft">{{ \Illuminate\Support\Str::headline(class_basename($notification->type)) }}</span>
                </button>
            @empty
                <p class="px-2 py-2 text-sm text-ink-soft">No new notifications.</p>
            @endforelse
        </div>
    </details>
</div>
