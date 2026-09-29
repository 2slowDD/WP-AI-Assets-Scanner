<?php
use PHPUnit\Framework\TestCase;
use DrSpeedAIAS\Cdn\Registry;
use DrSpeedAIAS\Cdn\AdapterInterface;

final class CdnRegistryTest extends TestCase {
    public function test_detect_returns_matching_adapter(): void {
        $fake = new class implements AdapterInterface {
            public function name(): string { return 'fake'; }
            public function detect(array $h): bool { return isset($h['x-fake']); }
            public function instructionsHtml(string $s): string { return ''; }
            public function supportsRateLimitSkip(): bool { return true; }
            public function isValidated(): bool { return false; }
        };
        $reg = new Registry([$fake]);
        $this->assertSame($fake, $reg->detect(['x-fake' => '1']));
        $this->assertNull($reg->detect(['server' => 'nginx']));
    }
}
