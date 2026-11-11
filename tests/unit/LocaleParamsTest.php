<?php

namespace ErnestDefoe\CommunityGoals\Tests\unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Flarum's translator treats every {name} in a string as a parameter, so a
 * string called without it shows "{undefined}" (the announcement help did, 1.0.0).
 * Every literal t('key') call in js/src must pass each {name} its string uses.
 */
class LocaleParamsTest extends TestCase
{
    public function test_every_js_call_passes_the_parameters_its_string_uses(): void
    {
        $locale = Yaml::parseFile(__DIR__.'/../../locale/en.yml')['ernestdefoe-community-goals'];
        $missing = [];

        foreach (['forum', 'admin'] as $area) {
            $strings = $this->flatten($locale[$area]);
            $calls = 0;
            foreach (glob(__DIR__."/../../js/src/$area/{,*/}*.{ts,tsx}", GLOB_BRACE) as $file) {
                preg_match_all("/\\bt\\('([\\w.]+)'(\\s*,\\s*([^)]*))?/", file_get_contents($file), $found, PREG_SET_ORDER);
                foreach ($found as $call) {
                    $calls++;
                    $this->assertArrayHasKey($call[1], $strings, "$area.{$call[1]} is not in locale/en.yml");
                    // Plural/select arguments ({count, plural, …}) and simple ones ({title}) alike.
                    preg_match_all('/\{(\w+)[,}]/', $strings[$call[1]], $names);
                    foreach (array_unique($names[1]) as $name) {
                        $params = $call[3] ?? '';
                        if (! preg_match('/\b'.$name.'\b|^[A-Z_]+$/', trim($params))) {
                            $missing[] = basename($file).": $area.{$call[1]} needs {{$name}}";
                        }
                    }
                }
            }
            $this->assertGreaterThan(0, $calls, "no t() calls found in js/src/$area");
        }

        $this->assertSame([], $missing);
    }

    public function test_the_announcement_legend_explains_exactly_what_the_reward_fills_in(): void
    {
        $legend = Yaml::parseFile(__DIR__.'/../../locale/en.yml')['ernestdefoe-community-goals']['forum']['form']['fields'];
        preg_match_all("/'\\{(\\w+)\\}' =>/", file_get_contents(__DIR__.'/../../src/Reward/Announcement.php'), $filled);
        preg_match("/const FIELDS = \\[([^\\]]+)\\]/", file_get_contents(__DIR__.'/../../js/src/forum/components/GoalModal.tsx'), $js);
        preg_match_all("/'(\\w+)'/", $js[1] ?? '', $passed);

        $named = array_keys($legend);
        sort($named);
        sort($filled[1]);
        sort($passed[1]);
        $this->assertNotEmpty($filled[1]);
        $this->assertSame($filled[1], $named);
        $this->assertSame($filled[1], $passed[1]);

        // The legend is translated without parameters (t(`form.fields.${field}`)): no {name} may appear in it.
        foreach ($legend as $field => $meaning) {
            $this->assertDoesNotMatchRegularExpression('/\\{\\w+[,}]/', $meaning, "form.fields.$field");
        }
    }

    private function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            is_array($value) ? $out += $this->flatten($value, "$prefix$key.") : $out["$prefix$key"] = (string) $value;
        }

        return $out;
    }
}
