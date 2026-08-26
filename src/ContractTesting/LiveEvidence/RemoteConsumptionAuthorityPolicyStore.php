<?php

declare(strict_types=1);

namespace Cieplik206\Fakturownia\ContractTesting\LiveEvidence;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use SensitiveParameter;

final class RemoteConsumptionAuthorityPolicyStore
{
    public const Contract = 'cieplik206.fakturownia.remote-consumption-authorities';

    public const Version = '1';

    public const RepositoryPath = 'tests/Fixtures/Contract/trusted-consumption-authorities.json';

    public static function load(
        #[SensitiveParameter] string $repositoryRoot,
        #[SensitiveParameter] string $authorityId,
    ): RemoteConsumptionAuthorityPolicy {
        $contents = PinnedRepositorySnapshotReader::read($repositoryRoot, self::RepositoryPath);

        try {
            $store = \json_decode($contents, true, 32, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The pinned remote authority policy store is invalid JSON.', previous: $exception);
        }

        if (! \is_array($store) || \array_is_list($store)) {
            throw new RuntimeException('The pinned remote authority policy store must be a JSON object.');
        }

        $keys = \array_keys($store);
        \sort($keys);

        if ($keys !== ['authorities', 'contract', 'version']
            || ($store['contract'] ?? null) !== self::Contract
            || ($store['version'] ?? null) !== self::Version
            || ! \is_array($store['authorities'] ?? null)
            || ! \array_is_list($store['authorities'])) {
            throw new RuntimeException('The pinned remote authority policy store has an invalid exact contract.');
        }

        $policies = [];
        $seenStores = [];
        $seenEndpoints = [];
        $seenPolicyDigests = [];
        $seenAuthorityKeyFingerprints = [];

        foreach ($store['authorities'] as $value) {
            if (! \is_array($value) || \array_is_list($value)) {
                throw new RuntimeException('The pinned remote authority policy store contains an invalid policy.');
            }

            try {
                $policy = RemoteConsumptionAuthorityPolicy::fromArray($value);
            } catch (InvalidArgumentException $exception) {
                throw new RuntimeException('The pinned remote authority policy store contains an invalid policy.', previous: $exception);
            }

            $digest = $policy->sha256();

            if (isset($policies[$policy->authorityId])
                || isset($seenStores[$policy->storeId])
                || isset($seenEndpoints[$policy->endpoint])
                || isset($seenPolicyDigests[$digest])
                || isset($seenAuthorityKeyFingerprints[$policy->authorityPublicKeySha256])) {
                throw new RuntimeException('The pinned remote authority policy store contains duplicate authority material.');
            }

            $policies[$policy->authorityId] = $policy;
            $seenStores[$policy->storeId] = true;
            $seenEndpoints[$policy->endpoint] = true;
            $seenPolicyDigests[$digest] = true;
            $seenAuthorityKeyFingerprints[$policy->authorityPublicKeySha256] = true;
        }

        $policy = $policies[$authorityId] ?? null;

        if (! $policy instanceof RemoteConsumptionAuthorityPolicy) {
            throw new RuntimeException('No pinned remote consumption authority policy is provisioned for this authorization.');
        }

        return $policy;
    }
}
