<?php
declare(strict_types=1);

session_save_path(sys_get_temp_dir());
require __DIR__ . '/../functions/helpers.php';

$passed = [];
$assert = static function (bool $condition, string $label) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    $passed[] = $label;
};

$temporaryDirectory = sys_get_temp_dir() . '/fj-logo-test-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryDirectory, 0700) && !is_dir($temporaryDirectory)) {
    throw new RuntimeException('Unable to create the logo test directory.');
}

try {
    $assert(resolveCompanyLogoUrl('', '../') === '', 'empty logo uses the text fallback');
    $assert(resolveCompanyLogoUrl('https://example.test/logo.png', '../') === 'https://example.test/logo.png',
        'legacy HTTPS logo URLs remain supported');
    $assert(resolveCompanyLogoUrl('/legacy/logo.png', '../') === '/legacy/logo.png',
        'legacy root-relative logo paths remain supported');
    $assert(resolveCompanyLogoUrl('assets/uploads/company-logo-0123456789abcdef0123456789abcdef.png', '../')
        === '../assets/uploads/company-logo-0123456789abcdef0123456789abcdef.png',
        'managed logo paths resolve relative to portal pages');
    $assert(resolveCompanyLogoUrl('assets/uploads/../config/database.php', '../') === '',
        'logo path traversal is rejected');

    $pngPath = $temporaryDirectory . '/valid.png';
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    if ($pngBytes === false || file_put_contents($pngPath, $pngBytes) === false) {
        throw new RuntimeException('Unable to create the PNG test fixture.');
    }
    $validPng = validateCompanyLogoImageFile($pngPath, strlen($pngBytes));
    $assert($validPng['valid'] && $validPng['extension'] === 'png',
        'a genuine PNG image passes MIME and image validation');

    $imageFixtures = [
        'jpg' => '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2OTApLCBxdWFsaXR5ID0gOTAK/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU/8AAEQgAAQABAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A/KqiiigD/9lKUEVHPQ==',
        'webp' => 'UklGRiYAAABXRUJQVlA4IBoAAAAwAQCdASoBAAEAAMASJaQAA3AA/v7uqgAAAFdFQlA9',
    ];
    foreach ($imageFixtures as $extension => $encodedFixture) {
        $fixtureBytes = base64_decode($encodedFixture, true);
        $fixturePath = $temporaryDirectory . '/valid.' . $extension;
        if ($fixtureBytes === false || file_put_contents($fixturePath, $fixtureBytes) === false) {
            throw new RuntimeException('Unable to create the ' . strtoupper($extension) . ' test fixture.');
        }
        $validation = validateCompanyLogoImageFile($fixturePath, strlen($fixtureBytes));
        $assert($validation['valid'] && $validation['extension'] === $extension,
            'a genuine ' . strtoupper($extension) . ' image passes MIME and image validation');
    }

    $disguisedPath = $temporaryDirectory . '/disguised.png';
    file_put_contents($disguisedPath, '<?php echo "not an image";');
    $disguised = validateCompanyLogoImageFile($disguisedPath, (int)filesize($disguisedPath));
    $assert(!$disguised['valid'], 'a disguised executable file is rejected');

    $oversizedPath = $temporaryDirectory . '/oversized.png';
    file_put_contents($oversizedPath, str_repeat('x', COMPANY_LOGO_MAX_BYTES + 1));
    $oversized = validateCompanyLogoImageFile($oversizedPath, COMPANY_LOGO_MAX_BYTES + 1);
    $assert(!$oversized['valid'], 'an oversized file is rejected');

    $managedName = 'company-logo-abcdefabcdefabcdefabcdefabcdefab.png';
    $managedPath = $temporaryDirectory . '/' . $managedName;
    file_put_contents($managedPath, $pngBytes);
    deleteManagedCompanyLogo('assets/uploads/' . $managedName, $temporaryDirectory);
    $assert(!is_file($managedPath), 'managed logo cleanup removes a generated logo file');

    $unmanagedPath = $temporaryDirectory . '/unmanaged.png';
    file_put_contents($unmanagedPath, $pngBytes);
    deleteManagedCompanyLogo('assets/uploads/unmanaged.png', $temporaryDirectory);
    $assert(is_file($unmanagedPath), 'managed logo cleanup never removes an arbitrary upload');

    foreach ($passed as $label) {
        echo "PASS: $label\n";
    }
    echo 'Completed ' . count($passed) . " company logo assertions.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach (glob($temporaryDirectory . '/*') ?: [] as $temporaryFile) {
        if (is_file($temporaryFile)) {
            @unlink($temporaryFile);
        }
    }
    @rmdir($temporaryDirectory);
}
