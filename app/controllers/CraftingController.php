<?php

class CraftingController extends BaseController 
{

	public function getIndex()
	{
		// All Jobs
		$job_list = array();
		foreach (Job::all() as $j)
			$job_list[$j->abbreviation] = $j->name;

		return View::make('crafting')
			->with('error', FALSE)
			->with('active', 'crafting')
			->with('job_list', $job_list)
			->with('previous', Cookie::get('previous_crafting_load'));
	}

	public function postIndex()
	{
		$vars = array('class' => 'CRP', 'start' => 1, 'end' => 5, 'self_sufficient' => 0);
		$values = array();
		foreach ($vars as $var => $default)
			$values[] = Input::has($var) ? Input::get($var) : $default;

		// Overwrite Class var
		if (Input::has('multi') && Input::has('classes'))
			$values[0] = implode(',', Input::get('classes'));

		$url = '/crafting/list?' . implode(':', $values);

		// Queueing the cookie, we won't need it right away, so it'll save for the next Response::
		Cookie::queue('previous_crafting_load', $url, 525600); // 1 year's worth of minutes
		
		return Redirect::to($url);
	}

	public function getList()
	{
		View::share('active', 'crafting');

		// All Jobs
		$job_list = array();
		foreach (Job::all() as $j)
			$job_list[$j->abbreviation] = $j->name;

		View::share('job_list', $job_list);

		$include_quests = TRUE;

		if ( ! Input::all())
			return Redirect::to('/crafting');

		// Get Options
		$options = explode(':', array_keys(Input::all())[0]);

		// Parse Options              						// Defaults
		$desired_job     = isset($options[0]) ? $options[0] : 'CRP';
		$start           = isset($options[1]) ? $options[1] : 1;
		$end             = isset($options[2]) ? $options[2] : 5;
		$self_sufficient = isset($options[3]) ? $options[3] : 1;

		$item_ids = $item_amounts = array();

		$top_level = TRUE;

		if ($desired_job == 'List')
		{
			$start = $end = null;
			$include_quests = FALSE;

			// Get the list
			$item_amounts = Session::get('list', array());

			$item_ids = array_keys($item_amounts);

			if (empty($item_ids))
				return Redirect::to('/list');

			View::share('item_ids', $item_ids);
			View::share('item_amounts', $item_amounts);

			$top_level = $item_amounts;
		}

		if ( ! $item_ids)
		{
			// Jobs are capital
			$desired_job = strtoupper($desired_job);

			// Make sure it's a real job, jobs might be multiple
			$job = Job::whereIn('abbreviation', explode(',', $desired_job))->get();

			// If the job isn't real, error out
			if ( ! isset($job[0]))
			{
				// All Jobs
				$job_list = $job_ids = array();
				foreach (Job::all() as $j)
				{
					$job_ids[$j->abbreviation] = $j->id;
					$job_list[$j->abbreviation] = $j->name;
				}

				// Check for DOL quests
				$quests = array();
				foreach (array('MIN','BTN','FSH') as $job)
					$quests[$job] = QuestItem::where('job_id', $job_ids[$job])
						->orderBy('level')
						->with('item')
						->get();

				return View::make('crafting')
					->with('error', TRUE)
					->with('quests', $quests);
			}

			$job_ids = array();
			foreach ($job as $j)
				$job_ids[] = $j->id;

			if (count($job) == 1)
				$job = $job[0];

			// Starting maximum of 1
			if ($start < 0) $start = 1;
			if ($start > $end) $end = $start;
			if ($end - $start > 9) $end = $start + 9;

			// Check for quests
			$quest_items = QuestItem::with('job')
				->whereBetween('level', array($start, $end))
				->whereIn('job_id', $job_ids)
				->orderBy('level')
				->with('item')
				->get();

			View::share(array(
				'job' => $job,
				'start' => $start,
				'end' => $end,
				'quest_items' => $quest_items,
				'desired_job' => $desired_job
			));
		}

		// Gather Recipes and Reagents

		$query = Recipe::with(array(
				'item', // The recipe's Item
					'item.quest', // Is the recipe used as a quest turnin?
					'item.leve', // Is the recipe used to fufil a leve?
					'item.vendors',
						'item.vendors.location',
				'reagents', // The reagents for the recipe
					'reagents.vendors',
						'reagents.vendors.location',
					'reagents.nodes',
						'reagents.nodes.location',
						'reagents.nodes.job',
					'reagents.recipes', 
						'reagents.recipes.item', 
						'reagents.recipes.job' => function($query) {
							// Only Hand Disciples
							$query->where('disciple', 'DOH');
						}
			))
			->select('recipes.*', 'j.abbreviation')
			->join('jobs AS j', 'j.id', '=', 'recipes.job_id')
			->groupBy('recipes.item_id')
			->orderBy('level');

		if ($item_ids)
			$query
				->whereIn('recipes.item_id', $item_ids);
		else
			$query
				->whereIn('j.id', $job_ids)
				->whereBetween('level', array($start, $end));

		$recipes = $query
			->remember(Config::get('site.cache_length'))
			->get();

		// Fix the amount of the top level to be evenly divisible by the amount the recipe yields
		if (is_array($top_level))
		{
			foreach ($recipes as $recipe)
			{
				$tl_item =& $top_level[$recipe->item_id];

				// If they're not evently divisible
				if ($tl_item % $recipe->yields != 0)
					// Make it so
					$tl_item = ceil($tl_item / $recipe->yields) * $recipe->yields;
			}
			unset($tl_item);

			View::share('item_amounts', $top_level);
		}

		$reagent_list = $this->_reagents($recipes, $self_sufficient, 1, $include_quests, $top_level);

		// Look through the list.  Is there something we're already crafting?
		// Subtract what's being made from needed reagents.
		//  Example, culinary 11 to 15, you need olive oil for Parsnip Salad (lvl 13)
		//   But you make 3 olive oil at level 11.  We don't want them crafting another olive oil.
		
		foreach ($recipes as $recipe)
		{
			if ( ! isset($reagent_list[$recipe->item_id]))
				continue;

			$reagent_list[$recipe->item_id]['both_list_warning'] = TRUE;
			$reagent_list[$recipe->item_id]['make_this_many'] += 1;
		}

		// Look through the reagent list, make sure the reagents are evently divisible by what they yield
		foreach ($reagent_list as &$reagent)
			// If they're not evently divisible
			if ($reagent['make_this_many'] % $reagent['yields'] != 0)
				// Make it so
				$reagent['make_this_many'] = ceil($reagent['make_this_many'] / $reagent['yields']) * $reagent['yields'];
		unset($reagent);

		// Let's sort them further, group them by..
		// Gathered, Then by Level
		// Other (likely mob drops)
		// Crafted, Then by level
		// Bought, by price

		$sorted_reagent_list = array(
			'Gathered' => array(),
			'Bought' => array(),
			'Other' => array(),
			'Pre-Requisite Crafting' => array(),
			'Crafting List' => array(),
		);

		foreach ($reagent_list as $reagent)
		{
			$section = 'Other';
			$level = 0;

			// Vendors
			$reagent['vendor_count'] = 0;
			$reagent['vendors'] = array();

			if (count($reagent['item']->vendors))
			{
				foreach($reagent['item']->vendors as $vendor)
				{
					$reagent['vendors'][isset($vendor->location->name) ? $vendor->location->name : 'Unknown'][] = (object) array(
						'name' => $vendor->name,
						'title' => $vendor->title,
						'x' => $vendor->x,
						'y' => $vendor->y
					);

					$reagent['vendor_count']++;
				}

				ksort($reagent['vendors']);
			}

			// Nodes
			$new_nodes = array();
			// Job
				// Location
					// Action
						//Level
			foreach ($reagent['nodes'] as $node)
				$new_nodes[$node['job']][$node['location']][$node['action']][] = $node['level'];

			foreach ($new_nodes as $k => $nn)
			{
				foreach ($nn as $j => $nl)
				{
					foreach ($nl as $h => $na)
						ksort($new_nodes[$k][$j][$h]);

					ksort($new_nodes[$k][$j]);
				}
				ksort($new_nodes[$k]);
			}

			$reagent['nodes'] = $new_nodes;

			// Section
			if (in_array($reagent['self_sufficient'], array('MIN', 'BTN', 'FSH')))
			{
				$section = 'Gathered';
				$level = $reagent['item']->level;
			}
			elseif ($reagent['self_sufficient'])
			{
				$section = 'Pre-Requisite Crafting';
				$level = $reagent['item']->recipes[0]->level;
			}
			elseif ($reagent['item']->gil)
			{
				$section = 'Bought';
				$level = $reagent['item']->gil;
			}

			if ( ! isset($sorted_reagent_list[$section][$level]))
				$sorted_reagent_list[$section][$level] = array();

			$sorted_reagent_list[$section][$level][$reagent['item']->id] = $reagent;
			ksort($sorted_reagent_list[$section][$level]);
		}

		foreach ($sorted_reagent_list as $section => $list)
			ksort($sorted_reagent_list[$section]);

		// Recipe Vendors
		$recipe_vendors = array();
		foreach ($recipes as $recipe)
		{
			// Vendors
			$vendor_count = 0;
			$new_vendors = array();

			if (count($recipe->item->vendors))
			{
				foreach($recipe->item->vendors as $vendor)
				{
					$new_vendors[isset($vendor->location->name) ? $vendor->location->name : 'Unknown'][] = (object) array(
						'name' => $vendor->name,
						'title' => $vendor->title,
						'x' => $vendor->x,
						'y' => $vendor->y
					);

					$vendor_count++;
				}

				ksort($new_vendors);
			}

			$recipe_vendors[$recipe->id] = array(
				'count' => $vendor_count,
				'vendors' => $new_vendors
			);
		}

		return View::make('crafting.list')
			->with(array(
				'recipes' => $recipes,
				'recipe_vendors' => $recipe_vendors,
				'reagent_list' => $sorted_reagent_list,
				'self_sufficient' => $self_sufficient,
				'include_quests' => $include_quests
			));
	}

	private function _reagents($recipes = array(), $self_sufficient = FALSE, $multiplier = 1, $include_quests = FALSE, $top_level = FALSE)
	{
		static $reagent_list = array();

		foreach ($recipes as $recipe)
		{
			$inner_multiplier = $multiplier;

			// Recipe may be involved in a Guildmaster quest.  They may need to make this multiple times.
			// But only account for the top level recipes
			if ($include_quests == TRUE)
			{
				$run = 0;
				
				if ($recipe->item)
					foreach ($recipe->item->quest as $quest)
						$run += ceil($quest->amount / $recipe->yields);

				// Run everything at least once
				$inner_multiplier *= $run ?: 1;
			} 
			elseif (is_array($top_level))
			{
				$run = 0;

				if (in_array($recipe->item_id, array_keys($top_level)))
					$run += $top_level[$recipe->item_id];

				$inner_multiplier *= floor($run ?: 1);
			}

			if ( ! is_array($top_level))
				$inner_multiplier *= $recipe->yields;

			foreach ($recipe->reagents as $reagent)
			{
				$reagent_yields = isset($reagent->recipes[0]) ? $reagent->recipes[0]->yields : 1;

				if ( ! isset($reagent_list[$reagent->id]))
					$reagent_list[$reagent->id] = array(
						'make_this_many' => 0,
						'self_sufficient' => '',
						'item' => $reagent,
						'nodes' => array(),
						'node_jobs' => array(),
						'yields' => 1
					);

				$make_this_many = ceil($reagent->pivot->amount * $inner_multiplier); // ceil($reagent->pivot->amount * ceil($inner_multiplier / $reagent_yields))
				$reagent_list[$reagent->id]['make_this_many'] += $make_this_many;

				if ($self_sufficient)
				{
					if (count($reagent->nodes))
					{
						// First, check here because we don't want to re-process the node data
						if ($reagent_list[$reagent->id]['self_sufficient'])
							continue;

						// Compile nodes
						$node_data = $node_jobs = array();
						foreach ($reagent->nodes as $node)
						{
							@$node_jobs[$node->job->abbreviation]++;
							$node_data[] = array(
								'action' => $node->action,
								'level' => $node->level,
								'location' => $node->location->name,
								'job' => $node->job->abbreviation
							);
						}
						// Get the "highest" job
						asort($node_jobs);

						$reagent_list[$reagent->id]['self_sufficient'] = array_keys($node_jobs)[count($node_jobs) - 1];

						$reagent_list[$reagent->id]['nodes'] = $node_data;
						$reagent_list[$reagent->id]['node_jobs'] = $node_jobs;

						// Then check again here to avoid recipe stuff
						if ($reagent_list[$reagent->id]['self_sufficient'])
							continue;
					}

					if(isset($reagent->recipes[0]))
					{
						$reagent_list[$reagent->id]['yields'] = $reagent->recipes[0]->yields;
						$reagent_list[$reagent->id]['self_sufficient'] = $reagent->recipes[0]->job->abbreviation;
						$this->_reagents(array($reagent->recipes[0]), $self_sufficient, ceil($reagent->pivot->amount * ceil($inner_multiplier / $reagent_yields)));
					}
				}
			}
		}

		return $reagent_list;
	}

	public function postList()
	{
		return $this->getList();
	}

}