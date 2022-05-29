<?php

namespace HardG\ElasticPressBuddyPress;

use ElasticPress\Features as Features;

class App {
	public static function init() {
		Features::factory()->register_feature(
			new Feature\Groups\Groups()
		);
	}
}
