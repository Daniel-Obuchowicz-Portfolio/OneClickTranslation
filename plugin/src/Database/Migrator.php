<?php

declare(strict_types=1);

namespace OneClickTranslation\Database;

final class Migrator {

	public function maybeMigrate(): void {
		if ( get_option( 'oct_db_version' ) !== Installer::DB_VERSION ) {
			Installer::install();
		}
	}
}
