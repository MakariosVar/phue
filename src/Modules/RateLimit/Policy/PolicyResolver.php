<?php

declare(strict_types=1);

namespace Phue\Modules\RateLimit\Policy;

use Phue\Core\Config;
use Phue\Modules\RateLimit\Attribute\RateLimit;
use ReflectionClass;
use ReflectionMethod;

/**
 * Resolves rate limit policy from cascade:
 * Global config → Middleware group → Controller attribute → Route attribute
 *
 * @package Phue\Modules\RateLimit\Policy
 */
final class PolicyResolver
{
    /**
     * @param Config $config Application config
     */
    public function __construct(
        private Config $config
    ) {}

    /**
     * Resolve the effective policy for a route.
     *
     * @param ReflectionClass  $controllerClass Controller class
     * @param ReflectionMethod $method          Controller method
     * @param string|null      $groupName       Middleware group name (from route registration)
     * @return RateLimitPolicy|null Resolved policy, or null if rate limiting disabled
     */
    public function resolve(
        ReflectionClass $controllerClass,
        ReflectionMethod $method,
        ?string $groupName = null
    ): ?RateLimitPolicy {
        if (!$this->isEnabled()) {
            return null;
        }

        // 1. Start with global default
        $policy = $this->getDefaultPolicy();

        // 2. Apply middleware group policy if applicable
        if ($groupName !== null) {
            $groupPolicy = $this->getGroupPolicy($groupName);
            if ($groupPolicy !== null) {
                $policy = $policy->merge($groupPolicy);
            }
        }

        // 3. Apply controller-level attribute
        $controllerAttr = $this->getControllerAttribute($controllerClass);
        if ($controllerAttr !== null) {
            $controllerPolicy = $this->createPolicyFromAttribute($controllerAttr);
            if ($controllerPolicy !== null) {
                $policy = $policy->merge($controllerPolicy);
            }
        }

        // 4. Apply method-level attribute (highest priority)
        $methodAttr = $this->getMethodAttribute($method);
        if ($methodAttr !== null) {
            $methodPolicy = $this->createPolicyFromAttribute($methodAttr);
            if ($methodPolicy !== null) {
                $policy = $policy->merge($methodPolicy);
            }
        }

        return $policy;
    }

    /**
     * Check if rate limiting is globally enabled.
     */
    private function isEnabled(): bool
    {
        return (bool) $this->config->get('rate-limit.enabled', true);
    }

    /**
     * Get default policy from config.
     */
    private function getDefaultPolicy(): RateLimitPolicy
    {
        $default = $this->config->get('rate-limit.default', []);
        if ($default === null) {
            return new RateLimitPolicy('default', 100, 'minute');
        }
        return RateLimitPolicy::fromArray('default', $default);
    }

    /**
     * Get policy for a middleware group.
     */
    private function getGroupPolicy(string $groupName): ?RateLimitPolicy
    {
        $groups = $this->config->get('rate-limit.groups', []);
        $policyRef = $groups[$groupName] ?? null;

        if ($policyRef === null || $policyRef === false) {
            return null;
        }

        if (is_string($policyRef)) {
            // Reference to named policy
            return $this->getNamedPolicy($policyRef);
        }

        if (is_array($policyRef)) {
            // Inline policy
            return RateLimitPolicy::fromArray("group.{$groupName}", $policyRef);
        }

        return null;
    }

    /**
     * Get a named policy from config.
     */
    private function getNamedPolicy(string $name): ?RateLimitPolicy
    {
        $policies = $this->config->get('rate-limit.policies', []);
        if (!isset($policies[$name])) {
            return null;
        }

        return RateLimitPolicy::fromArray($name, $policies[$name]);
    }

    /**
     * Get #[RateLimit] attribute from controller class.
     */
    private function getControllerAttribute(ReflectionClass $class): ?RateLimit
    {
        $attrs = $class->getAttributes(RateLimit::class);
        if (empty($attrs)) {
            return null;
        }
        return $attrs[0]->newInstance();
    }

    /**
     * Get #[RateLimit] attribute from controller method.
     */
    private function getMethodAttribute(ReflectionMethod $method): ?RateLimit
    {
        $attrs = $method->getAttributes(RateLimit::class);
        if (empty($attrs)) {
            return null;
        }
        return $attrs[0]->newInstance();
    }

    /**
     * Create policy from attribute, resolving named policy references.
     */
    private function createPolicyFromAttribute(RateLimit $attr): ?RateLimitPolicy
    {
        // If attribute references a named policy, look it up from config
        if ($attr->policy !== null) {
            return $this->getNamedPolicy($attr->policy);
        }

        // Otherwise create inline policy from attribute values
        return RateLimitPolicy::fromAttribute($attr);
    }
}