<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\LazyProxy\PhpDumper;

use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\VarExporter\Exception\LogicException;
use Symfony\Component\VarExporter\ProxyHelper;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class LazyServiceDumper implements DumperInterface
{
    public function __construct(
        private string $salt = '',
    ) {
    }

    public function isProxyCandidate(Definition $definition, bool &$asGhostObject = null, string $id = null): bool
    {
        $asGhostObject = false;

        if ($definition->hasTag('proxy')) {
            if (!$definition->isLazy()) {
                throw new InvalidArgumentException(sprintf('Invalid definition for service "%s": setting the "proxy" tag on a service requires it to be "lazy".', $id ?? $definition->getClass()));
            }

            return true;
        }

        if (!$definition->isLazy()) {
            return false;
        }

        if (!($class = $definition->getClass()) || !(class_exists($class) || interface_exists($class, false))) {
            return false;
        }

        if ($definition->getFactory()) {
            return true;
        }

        foreach ($definition->getMethodCalls() as $call) {
            if ($call[2] ?? false) {
                return true;
            }
        }

        try {
            $asGhostObject = (bool) ProxyHelper::generateLazyGhost(new \ReflectionClass($class));
        } catch (LogicException) {
        }

        return true;
    }

    public function getProxyFactoryCode(Definition $definition, string $id, string $factoryCode): string
    {
        $instantiation = 'return';

        if ($definition->isShared()) {
            $instantiation .= sprintf(' $this->%s[%s] =', $definition->isPublic() && !$definition->isPrivate() ? 'services' : 'privates', var_export($id, true));
        }

        $proxyClass = $this->getProxyClass($definition);

        if (!str_contains($factoryCode, '$proxy')) {
            return <<<EOF
                    if (true === \$lazyLoad) {
                        $instantiation \$this->createProxy('$proxyClass', fn () => \\$proxyClass::createLazyProxy(fn () => $factoryCode));
                    }


            EOF;
        }

        if (preg_match('/^\$this->\w++\(\$proxy\)$/', $factoryCode)) {
            $factoryCode = substr_replace($factoryCode, '(...)', -8);
        } else {
            $factoryCode = sprintf('fn ($proxy) => %s', $factoryCode);
        }

        return <<<EOF
                if (true === \$lazyLoad) {
                    $instantiation \$this->createProxy('$proxyClass', fn () => \\$proxyClass::createLazyGhost($factoryCode));
                }


        EOF;
    }

    public function getProxyCode(Definition $definition, string $id = null): string
    {
        if (!$this->isProxyCandidate($definition, $asGhostObject, $id)) {
            throw new InvalidArgumentException(sprintf('Cannot instantiate lazy proxy for service "%s".', $id ?? $definition->getClass()));
        }
        $proxyClass = $this->getProxyClass($definition, $class);

        if ($asGhostObject) {
            try {
                return 'class '.$proxyClass.ProxyHelper::generateLazyGhost($class);
            } catch (LogicException $e) {
                throw new InvalidArgumentException(sprintf('Cannot generate lazy ghost for service "%s".', $id ?? $definition->getClass()), 0, $e);
            }
        }
        $interfaces = [];

        if ($definition->hasTag('proxy')) {
            foreach ($definition->getTag('proxy') as $tag) {
                if (!isset($tag['interface'])) {
                    throw new InvalidArgumentException(sprintf('Invalid definition for service "%s": the "interface" attribute is missing on a "proxy" tag.', $id ?? $definition->getClass()));
                }
                if (!interface_exists($tag['interface']) && !class_exists($tag['interface'], false)) {
                    throw new InvalidArgumentException(sprintf('Invalid definition for service "%s": several "proxy" tags found but "%s" is not an interface.', $id ?? $definition->getClass(), $tag['interface']));
                }
                $interfaces[] = new \ReflectionClass($tag['interface']);
            }

            if (1 === \count($interfaces) && !$interfaces[0]->isInterface()) {
                $class = array_pop($interfaces);
            }
        } elseif ($class->isInterface()) {
            $interfaces = [$class];
            $class = null;
        }

        try {
            return (\PHP_VERSION_ID >= 80200 && $class?->isReadOnly() ? 'readonly ' : '').'class '.$proxyClass.ProxyHelper::generateLazyProxy($class, $interfaces);
        } catch (LogicException $e) {
            throw new InvalidArgumentException(sprintf('Cannot generate lazy proxy for service "%s".', $id ?? $definition->getClass()), 0, $e);
        }
    }

    public function getProxyClass(Definition $definition, \ReflectionClass &$class = null): string
    {
        $class = new \ReflectionClass($definition->getClass());

        return preg_replace('/^.*\\\\/', '', $class->name).'_'.substr(hash('sha256', $this->salt.'+'.$class->name), -7);
    }
}
