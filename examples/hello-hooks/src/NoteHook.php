<?php

declare(strict_types=1);

namespace Xaraya\Module\HelloHooks;

use Xaraya\Kernel\Hooks\DisplayHook;
use Xaraya\Kernel\Settings\Settings;

/** Lets any hooked item carry a short note, stored in xar_settings (scope "hello-hooks"). */
final class NoteHook implements DisplayHook
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(string $hook, array $item, array $input = []): string
    {
        $id = is_string($item['id'] ?? null) ? $item['id'] : '';

        return match ($hook) {
            'item.display' => $this->display($id),
            'item.form' => '<p><label for="hello-hooks-note">Note</label> <input id="hello-hooks-note" name="note" maxlength="200" value="' . self::e($this->note($id)) . '"></p>',
            'item.form.save' => $this->save($id, $input),
            default => '',
        };
    }

    private function display(string $id): string
    {
        $note = $this->note($id);

        return '<aside class="hello-hooks" aria-label="Note">' . ($note === '' ? 'No note yet.' : 'Note: ' . self::e($note)) . '</aside>';
    }

    /** @param array<string, mixed> $input */
    private function save(string $id, array $input): string
    {
        $note = is_string($input['note'] ?? null) ? trim(mb_substr($input['note'], 0, 200)) : '';
        if ($id !== '' && $note !== '') {
            $this->settings->set('hello-hooks', 'note.' . $id, $note);
        }

        return '';
    }

    private function note(string $id): string
    {
        $note = $id === '' ? null : $this->settings->get('hello-hooks', 'note.' . $id);

        return is_string($note) ? $note : '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
