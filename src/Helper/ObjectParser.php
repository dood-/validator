<?php

declare(strict_types=1);

namespace Yiisoft\Validator\Helper;

use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\ExpectedValues;
use ReflectionAttribute;
use ReflectionObject;
use ReflectionProperty;
use Yiisoft\Validator\AfterInitAttributeEventInterface;
use Yiisoft\Validator\RuleInterface;

use function array_key_exists;

/**
 * A helper class used to parse rules from PHP attributes (attached to class properties and class itself) and data from
 * object properties. The attributes introduced in PHP 8 simplify rules' configuration process, especially for nested
 * data and relations. This way the validated structures can be presented as DTO classes with references to each other.
 *
 * An example of parsed object with both one-to-one and one-to-many relations:
 *
 * ```php
 * final class Post
 * {
 *     #[HasLength(max: 255)]
 *     public string $title = '';
 *
 *     #[Nested]
 *     public Author $author;
 *
 *     #[Each(new Nested([File::class])]
 *     public array $files;
 *
 *     public function __construct()
 *     {
 *         $this->author = new Author();
 *     }
 * }
 *
 * final class Author
 * {
 *     #[HasLength(min: 1)]
 *     public string $name = '';
 * }
 *
 * // Some rules, like "Nested" can be also configured through the class attribute.
 *
 * #[Nested(['url' => new Url()])]
 * final class File
 * {
 *     public string $url = '';
 * }
 *
 * $post = new Post();
 * $parser = new ObjectParser($post);
 * $rules = $parser->getRules();
 * ```
 *
 * The parsed `$rules` will contain:
 *
 * ```
 * $rules = [
 *     new Nested([
 *         'title' => [new HasLength(max: 255)],
 *         'author' => new Nested([
 *             'name' => [new HasLength(min: 1)],
 *         ]),
 *         'files' => new Each(new Nested([
 *             'url' => [new Url()],
 *         ])),
 *     ]);
 * ];
 * ```
 *
 * Please refer to the guide for more examples.
 *
 * Note that the rule attributes can be combined with others without affecting parsing. Which properties to parse can be
 * configured via {@see ObjectParser::$propertyVisibility} and {@see ObjectParser::$skipStaticProperties} options.
 *
 * Uses Reflection for getting object data and metadata. Supports caching for Reflection of object with properties and
 * rules which can be disabled on demand.
 *
 * @link https://www.php.net/manual/en/language.attributes.overview.php
 */
final class ObjectParser
{
    /**
     * @var array<string, array<string, mixed>> A cache storage utilizing static class property:
     *
     * - The first nesting level is a mapping between cache keys (dynamically generated on instantiation) and item names
     * (one of: `rules`, `reflectionProperties`, `reflectionObject`).
     * - The second nesting level is a mapping between cache item names and their contents.
     *
     * Different properties' combinations of the same object are cached separately.
     */
    #[ArrayShape([
        [
            'rules' => 'array',
            'reflectionAttributes' => 'array',
            'reflectionObject' => 'object',
        ],
    ])]
    private static array $cache = [];
    /**
     * @var string|null A cache key. Dynamically generated on instantiation.
     */
    private string|null $cacheKey;

    public function __construct(
        /**
         * @var object An object for parsing rules and data.
         */
        private object $object,
        /**
         * @var int A constant determining what visibility levels the parsed properties must have. For example: public
         * and protected only, this means that the rest (private ones) will be skipped. Defaults to all visibility
         * levels (public, protected and private).
         */
        private int $propertyVisibility = ReflectionProperty::IS_PRIVATE |
        ReflectionProperty::IS_PROTECTED |
        ReflectionProperty::IS_PUBLIC,
        /**
         * @var bool A flag indicating whether the properties with "static" modifier must be skipped.
         */
        private bool $skipStaticProperties = false,
        /**
         * @var bool A flag indicating whether some results of parsing (Reflection of object with properties and
         * rules) must be cached.
         */
        bool $useCache = true,
    ) {
        $this->cacheKey = $useCache
            ? $this->object::class . '_' . $this->propertyVisibility . '_' . $this->skipStaticProperties
            : null;
    }

    /**
     * Parses rules specified via attributes attached to class properties and class itself. Repetitive calls utilize
     * cache if it's enabled in {@see $useCache}.
     *
     * @return array<int, RuleInterface>|array<string, list<RuleInterface>>
     */
    public function getRules(): array
    {
        if ($this->hasCacheItem('rules')) {
            /** @var array<int, RuleInterface>|array<string, list<RuleInterface>> */
            return $this->getCacheItem('rules');
        }

        $rules = [];

        // Class rules
        $attributes = $this
            ->getReflectionObject()
            ->getAttributes(RuleInterface::class, ReflectionAttribute::IS_INSTANCEOF);
        foreach ($attributes as $attribute) {
            $rules[] = $this->createRule($attribute);
        }

        // Properties rules
        foreach ($this->getReflectionProperties() as $property) {
            // TODO: use Generator to collect attributes.
            $attributes = $property->getAttributes(RuleInterface::class, ReflectionAttribute::IS_INSTANCEOF);
            foreach ($attributes as $attribute) {
                /** @psalm-suppress UndefinedInterfaceMethod */
                $rules[$property->getName()][] = $this->createRule($attribute);
            }
        }

        $this->setCacheItem('rules', $rules);

        return $rules;
    }

    /**
     * Returns a property value of the parsed object. Defaults are handled too.
     *
     * Note that in case of non-existing property a default `null` value is returned. If you need to check the presence
     * of property or return a different default value, use {@see hasAttribute()} instead.
     *
     * @param string $attribute Attribute name.
     *
     * @return mixed Attribute value.
     */
    public function getAttributeValue(string $attribute): mixed
    {
        return ($this->getReflectionProperties()[$attribute] ?? null)?->getValue($this->object);
    }

    /**
     * Whether the parsed object has the property with a given name. Note that this means existence only and properties
     * with empty values are treated as present too.
     *
     * @return bool Whether the property exists: `true` - exists and `false` - otherwise.
     */
    public function hasAttribute(string $attribute): bool
    {
        return array_key_exists($attribute, $this->getReflectionProperties());
    }

    /**
     * Returns the parsed object's data as a whole in a form of associative array.
     *
     * @return array  A mapping between property names and their values.
     */
    public function getData(): array
    {
        $data = [];
        foreach ($this->getReflectionProperties() as $name => $property) {
            /** @var mixed */
            $data[$name] = $property->getValue($this->object);
        }

        return $data;
    }

    /**
     * Returns Reflection properties parsed from {@see $object} in accordance with {@see $propertyVisibility} and
     * {@see $skipStaticProperties} values. Repetitive calls utilize cache if it's enabled in {@see $useCache}.
     *
     * @return array<string, ReflectionProperty>
     */
    private function getReflectionProperties(): array
    {
        if ($this->hasCacheItem('reflectionProperties')) {
            /** @var array<string, ReflectionProperty> */
            return $this->getCacheItem('reflectionProperties');
        }

        $reflection = $this->getReflectionObject();

        $reflectionProperties = [];

        foreach ($reflection->getProperties($this->propertyVisibility) as $property) {
            if ($this->skipStaticProperties && $property->isStatic()) {
                continue;
            }

            if (PHP_VERSION_ID < 80100) {
                $property->setAccessible(true);
            }

            $reflectionProperties[$property->getName()] = $property;
        }

        $this->setCacheItem('reflectionProperties', $reflectionProperties);

        return $reflectionProperties;
    }

    /**
     * Returns Reflection of {@see $object}. Repetitive calls utilize cache if it's enabled in {@see $useCache}.
     */
    private function getReflectionObject(): ReflectionObject
    {
        if ($this->hasCacheItem('reflectionObject')) {
            /** @var ReflectionObject */
            return $this->getCacheItem('reflectionObject');
        }

        $reflectionObject = new ReflectionObject($this->object);
        $this->setCacheItem('reflectionObject', $reflectionObject);

        return $reflectionObject;
    }

    /**
     * Creates a rule instance from a Reflection attribute.
     *
     * @param ReflectionAttribute<RuleInterface> $attribute Reflection attribute.
     *
     * @return RuleInterface A new rule instance.
     */
    private function createRule(ReflectionAttribute $attribute): RuleInterface
    {
        $rule = $attribute->newInstance();

        if ($rule instanceof AfterInitAttributeEventInterface) {
            $rule->afterInitAttribute($this->object);
        }

        return $rule;
    }

    /**
     * Whether a cache item with a given name exists in the cache. Note that this means existence only and items with
     * empty values are treated as present too.
     *
     * @param string $name Cache item name. Can be on of: `rules`, `reflectionProperties`, `reflectionObject`.
     *
     * @return bool `true` if a item exists, `false` - if it does not or cache is disabled in {@see $useCache}.
     */
    private function hasCacheItem(
        #[ExpectedValues(['rules', 'reflectionProperties', 'reflectionObject'])]
        string $name,
    ): bool {
        if (!$this->useCache()) {
            return false;
        }

        if (!array_key_exists($this->cacheKey, self::$cache)) {
            return false;
        }

        return array_key_exists($name, self::$cache[$this->cacheKey]);
    }

    /**
     * Returns a cache item by its name.
     *
     * @param string $name Cache item name. Can be on of: `rules`, `reflectionProperties`, `reflectionObject`.
     *
     * @return mixed Cache item value.
     */
    private function getCacheItem(
        #[ExpectedValues(['rules', 'reflectionProperties', 'reflectionObject'])]
        string $name,
    ): mixed {
        /** @psalm-suppress PossiblyNullArrayOffset */
        return self::$cache[$this->cacheKey][$name];
    }

    /**
     * Updates cache item contents by its name.
     *
     * @param string $name Cache item name. Can be on of: `rules`, `reflectionProperties`, `reflectionObject`.
     * @param mixed $value A new value.
     */
    private function setCacheItem(
        #[ExpectedValues(['rules', 'reflectionProperties', 'reflectionObject'])]
        string $name,
        mixed $value,
    ): void {
        if (!$this->useCache()) {
            return;
        }

        /** @psalm-suppress PossiblyNullArrayOffset, MixedAssignment */
        self::$cache[$this->cacheKey][$name] = $value;
    }

    /**
     * Whether the cache is enabled / can be used for a particular object.
     *
     * @psalm-assert string $this->cacheKey
     *
     * @return bool `true` if the cache is enabled / can be used and `false` otherwise.
     */
    private function useCache(): bool
    {
        return $this->cacheKey !== null;
    }
}
