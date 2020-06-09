<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Bridge\Amazon\Transport\SesTransportFactory;
use Symfony\Component\Mailer\Bridge\Google\Transport\GmailTransportFactory;
use Symfony\Component\Mailer\Bridge\Mailchimp\Transport\MandrillTransportFactory;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunTransportFactory;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkTransportFactory;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridTransportFactory;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\NullTransportFactory;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;
use Symfony\Component\Mailer\Transport\SendmailTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport\Transports;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Konstantin Myakshin <molodchick@gmail.com>
 */
class Transport
{
    private const FACTORY_CLASSES = [
        SesTransportFactory::class,
        GmailTransportFactory::class,
        MandrillTransportFactory::class,
        MailgunTransportFactory::class,
        PostmarkTransportFactory::class,
        SendgridTransportFactory::class,
    ];

    private $factories;

    public static function fromDsn(string $dsn, EventDispatcherInterface $dispatcher = null, HttpClientInterface $client = null, LoggerInterface $logger = null): TransportInterface
    {
        $factory = new self(iterator_to_array(self::getDefaultFactories($dispatcher, $client, $logger)));

        return $factory->fromString($dsn);
    }

    public static function fromDsns(array $dsns, EventDispatcherInterface $dispatcher = null, HttpClientInterface $client = null, LoggerInterface $logger = null): TransportInterface
    {
        $factory = new self(iterator_to_array(self::getDefaultFactories($dispatcher, $client, $logger)));

        return $factory->fromStrings($dsns);
    }

    /**
     * @param TransportFactoryInterface[] $factories
     */
    public function __construct(iterable $factories)
    {
        $this->factories = $factories;
    }

    public function fromStrings(array $dsns): Transports
    {
        $transports = [];
        foreach ($dsns as $name => $dsn) {
            $transports[$name] = $this->fromString($dsn);
        }

        return new Transports($transports);
    }

    public function fromString(string $dsn): TransportInterface
    {
        list($transport, $offset) = $this->parseDsn($dsn);
        if ($offset !== \strlen($dsn)) {
            throw new InvalidArgumentException(sprintf('The DSN has some garbage at the end: "%s".', substr($dsn, $offset)));
        }

        return $transport;
    }

    private function parseDsn(string $dsn, int $offset = 0): array
    {
        static $keywords = [
            'failover' => FailoverTransport::class,
            'roundrobin' => RoundRobinTransport::class,
        ];

        while (true) {
            foreach ($keywords as $name => $class) {
                $name .= '(';
                if ($name === substr($dsn, $offset, \strlen($name))) {
                    $offset += \strlen($name) - 1;
                    preg_match('{\(([^()]|(?R))*\)}A', $dsn, $matches, 0, $offset);
                    if (!isset($matches[0])) {
                        continue;
                    }

                    ++$offset;
                    $args = [];
                    while (true) {
                        list($arg, $offset) = $this->parseDsn($dsn, $offset);
                        $args[] = $arg;
                        if (\strlen($dsn) === $offset) {
                            break;
                        }
                        ++$offset;
                        if (')' === $dsn[$offset - 1]) {
                            break;
                        }
                    }

                    return [new $class($args), $offset];
                }
            }

            if (preg_match('{(\w+)\(}A', $dsn, $matches, 0, $offset)) {
                throw new InvalidArgumentException(sprintf('The "%s" keyword is not valid (valid ones are "%s"), ', $matches[1], implode('", "', array_keys($keywords))));
            }

            if ($pos = strcspn($dsn, ' )', $offset)) {
                return [$this->fromDsnObject(Dsn::fromString(substr($dsn, $offset, $pos))), $offset + $pos];
            }

            return [$this->fromDsnObject(Dsn::fromString(substr($dsn, $offset))), \strlen($dsn)];
        }
    }

    public function fromDsnObject(Dsn $dsn): TransportInterface
    {
        foreach ($this->factories as $factory) {
            if ($factory->supports($dsn)) {
                return $factory->create($dsn);
            }
        }

        throw new UnsupportedSchemeException($dsn);
    }

    private static function getDefaultFactories(EventDispatcherInterface $dispatcher = null, HttpClientInterface $client = null, LoggerInterface $logger = null): iterable
    {
        foreach (self::FACTORY_CLASSES as $factoryClass) {
            if (class_exists($factoryClass)) {
                yield new $factoryClass($dispatcher, $client, $logger);
            }
        }

        yield new NullTransportFactory($dispatcher, $client, $logger);

        yield new SendmailTransportFactory($dispatcher, $client, $logger);

        yield new EsmtpTransportFactory($dispatcher, $client, $logger);
    }
}
