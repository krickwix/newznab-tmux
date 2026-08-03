<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UsenetGroup;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Saving the group edit form unchanged must not change the group's min-files
 * behaviour.
 *
 * It used to. The template rendered `$group['minfilestoformrelease'] ?? 0`, so a
 * NULL override -- which means "fall through to the site setting" -- displayed as
 * 0, and 0 is not a weaker floor: both delete predicates in
 * ReleaseProcessingService are `> 0` guarded and effectiveGroupThreshold()
 * treats an explicit 0 as a real override, so posting it back DISABLED the
 * min-files delete for that group. 22 live groups carry an explicit 0, 7 of them
 * active and all in the alt.binaries.dvd.* family, which is exactly the shape a
 * few unchanged form saves would produce.
 *
 * Renders the real template rather than grepping it: the bug was in what the
 * value attribute evaluates to, and only rendering evaluates it.
 */
final class AdminGroupMinFilesRoundTripTest extends TestCase
{
    public function test_a_null_override_renders_as_blank_not_zero(): void
    {
        $html = $this->renderMinFilesField(['minfilestoformrelease' => null]);

        $this->assertStringContainsString('name="minfilestoformrelease"', $html);
        $this->assertStringContainsString('value=""', $html);
        // The regression in one assertion: a NULL override must never present
        // itself as the value that disables the check.
        $this->assertStringNotContainsString('value="0"', $html);
    }

    public function test_a_missing_key_renders_as_blank_too(): void
    {
        // The add-group path passes an array built by the controller; an absent
        // key must behave like NULL rather than like an explicit 0.
        $html = $this->renderMinFilesField([]);

        $this->assertStringContainsString('value=""', $html);
        $this->assertStringNotContainsString('value="0"', $html);
    }

    public function test_an_explicit_zero_still_renders_as_zero(): void
    {
        // A site that deliberately disabled the check must see that state, not a
        // blank field that would silently re-enable it on the next save.
        $html = $this->renderMinFilesField(['minfilestoformrelease' => 0]);

        $this->assertStringContainsString('value="0"', $html);
    }

    public function test_an_explicit_floor_survives_the_round_trip(): void
    {
        $html = $this->renderMinFilesField(['minfilestoformrelease' => 5]);

        $this->assertStringContainsString('value="5"', $html);
    }

    public function test_the_blank_the_form_posts_back_is_stored_as_null(): void
    {
        // The other half of the round-trip: updateGroup() maps '' to NULL, so a
        // blank field means "use the site setting" and not 0. Without this the
        // fix above would merely move the coercion downstream.
        $reflection = new \ReflectionMethod(UsenetGroup::class, 'updateGroup');
        $source = (string) file_get_contents((string) $reflection->getFileName());

        $this->assertStringContainsString(
            "'minfilestoformrelease' => \$group['minfilestoformrelease'] === '' ? null : \$group['minfilestoformrelease'],",
            $source
        );
    }

    public function test_the_help_text_states_what_zero_does(): void
    {
        // The semantics are not guessable from the label, and mistaking
        // "disabled" for "no minimum" is how a group stops being cleaned.
        $content = (string) file_get_contents(resource_path('views/admin/groups/edit.blade.php'));

        $this->assertStringContainsString('will use the site wide setting', $content);
        $this->assertStringContainsString('0 disables the check', $content);
    }

    /** @param array<string, mixed> $group */
    private function renderMinFilesField(array $group): string
    {
        $template = (string) file_get_contents(resource_path('views/admin/groups/edit.blade.php'));

        // The field alone: the surrounding form needs routes and a populated
        // request that say nothing about the value being asserted.
        $start = strpos($template, '<!-- Minimum Files -->');
        $this->assertNotFalse($start, 'The Minimum Files field is no longer marked in the template.');
        $end = strpos($template, '</div>', (int) strpos($template, 'minimum number of files'));
        $this->assertNotFalse($end);

        return Blade::render(
            substr($template, (int) $start, (int) $end - (int) $start),
            ['group' => $group]
        );
    }
}
