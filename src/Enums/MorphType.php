<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Toggl\Enums;

/**
 * Defines supported morph types for polymorphic relationships.
 *
 * This enum represents the available polymorphic relationship types that can
 * be used for context relationships in the features table. Each type corresponds
 * to a different identifier format with specific characteristics and is used to
 * configure the morph columns in database migrations.
 *
 * @author Brian Faust <brian@cline.sh>
 */
enum MorphType: string
{
    /**
     * Standard polymorphic relationship with auto-detected IDs.
     *
     * Uses Laravel's default morphs() method which automatically detects
     * the appropriate column type. Suitable for flexible polymorphic relationships.
     */
    case Morph = 'morph';

    /**
     * Polymorphic relationship with numeric (integer) IDs.
     *
     * Explicitly uses integer foreign keys for the morph relationship.
     * Suitable when models use standard auto-incrementing integer primary keys.
     */
    case Numeric = 'numericMorph';

    /**
     * Polymorphic relationship with UUID identifiers.
     *
     * Uses 36-character UUIDs for the morph relationship. Suitable when
     * models use UUID primary keys, providing cryptographic randomness.
     */
    case UUID = 'uuidMorph';

    /**
     * Polymorphic relationship with ULID identifiers.
     *
     * Uses 26-character ULIDs for the morph relationship. Suitable when
     * models use ULID primary keys, providing time-ordered global uniqueness.
     */
    case ULID = 'ulidMorph';
}
