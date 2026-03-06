<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use SEOAutomation\Connector\Admin\MenuRegistrar;

final class MenuRegistrarValidationTest extends TestCase
{
    public function testMetaDescriptionValidationRejectsOutOfRangeLengths(): void
    {
        $registrar = $this->newRegistrarWithoutConstructor();

        $validator = new ReflectionMethod($registrar, 'validateEditedPayload');
        $validator->setAccessible(true);

        $short = $validator->invoke($registrar, 'add-meta-description', [
            'meta_description' => str_repeat('a', 69),
        ]);
        $long = $validator->invoke($registrar, 'add-meta-description', [
            'meta_description' => str_repeat('a', 161),
        ]);
        $ok = $validator->invoke($registrar, 'add-meta-description', [
            'meta_description' => str_repeat('a', 120),
        ]);

        self::assertSame('Meta description must be between 70 and 160 characters.', $short);
        self::assertSame('Meta description must be between 70 and 160 characters.', $long);
        self::assertNull($ok);
    }

    public function testSocialTagsValidationRejectsInvalidTwitterHandle(): void
    {
        $registrar = $this->newRegistrarWithoutConstructor();

        $validator = new ReflectionMethod($registrar, 'validateEditedPayload');
        $validator->setAccessible(true);

        $invalid = $validator->invoke($registrar, 'set-social-tags', [
            'social_tags' => [
                'twitter' => ['site' => '@invalid-handle'],
            ],
        ]);

        $valid = $validator->invoke($registrar, 'set-social-tags', [
            'social_tags' => [
                'twitter' => ['site' => '@valid_handle'],
            ],
        ]);

        self::assertSame('Twitter/X handle must be 1-15 characters using letters, numbers, or underscore.', $invalid);
        self::assertNull($valid);
    }

    public function testSchemaValidationRequiresValidJsonWhenStringProvided(): void
    {
        $registrar = $this->newRegistrarWithoutConstructor();

        $validator = new ReflectionMethod($registrar, 'validateEditedPayload');
        $validator->setAccessible(true);

        $invalid = $validator->invoke($registrar, 'add-schema', [
            'schema_data' => '{"@type":"Article"',
        ]);

        $valid = $validator->invoke($registrar, 'add-schema', [
            'schema_data' => '{"@type":"Article"}',
        ]);

        self::assertSame('Schema payload must be valid JSON.', $invalid);
        self::assertNull($valid);
    }

    public function testEditableFieldMetadataIncludesValidationConstraints(): void
    {
        $registrar = $this->newRegistrarWithoutConstructor();

        $builder = new ReflectionMethod($registrar, 'buildEditableFields');
        $builder->setAccessible(true);

        $metaFields = $builder->invoke($registrar, 'add-meta-description', [
            'meta_description' => 'Test value',
        ]);
        $socialFields = $builder->invoke($registrar, 'set-social-tags', [
            'social_tags' => [
                'twitter' => ['site' => '@site'],
            ],
        ]);

        self::assertSame(70, $metaFields[0]['min_length']);
        self::assertSame(160, $metaFields[0]['max_length']);

        $twitterField = array_values(array_filter(
            $socialFields,
            static fn (array $field): bool => ($field['key'] ?? '') === 'social_tags_twitter_site'
        ));
        self::assertCount(1, $twitterField);
        self::assertSame('twitter_handle', $twitterField[0]['validation']);
    }

    private function newRegistrarWithoutConstructor(): MenuRegistrar
    {
        $ref = new ReflectionClass(MenuRegistrar::class);

        /** @var MenuRegistrar $instance */
        $instance = $ref->newInstanceWithoutConstructor();

        return $instance;
    }
}

