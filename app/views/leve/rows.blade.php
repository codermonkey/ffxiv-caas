@foreach($leves as $leve)
<tr>
	<td width='24' class='valign'>
		<img src='/img/classes/{{ $leve->job->abbreviation }}.png' rel='tooltip' title='{{ $leve->job->name }}'>
	</td>
	<td class='item{{ $leve->triple ? ' triple\' rel="tooltip" title="Triple Leve" data-placement="right" data-container=\'body' : '' }}'>
		<span class='close' rel='tooltip' title='Leve Level'>{{ $leve->level }}</span>
		<a href='http://xivdb.com/?recipe/{{ $leve->item->recipes->first()->id }}' class='item-name' target='_blank'><img src='/img/items/{{ $leve->item->icon ?: '../noitemicon' }}.png' style='margin-right: 10px;'>{{ $leve->item->name }}</a>
		@if ($leve->amount > 1)
		<span class='label label-primary' rel='tooltip' title='Amount Required' data-container='body'>
			x {{ $leve->amount }}
		</span>
		@endif
	</td>
	<td class='text-center name_type'>
		<span class='label label-success pull-left'>
			{{ $leve->type }}
		</span>
		{{ $leve->name }}
	</td>
	<td class='text-center reward valign'>
		{{ number_format($leve->xp) }} <a href='/leve/breakdown/{{ $leve->id }}'>XP</a>
	</td>
	<td class='text-center reward valign'>
		<img src='/img/coin.png' class='stat-vendors' width='24' height='24'>
		{{ number_format($leve->gil) }}
	</td>
	<td class='text-center location {{ preg_replace('/\W/', '', strtolower($leve->major->name)) }}'>
		<div>{{ ! empty($leve->location) ? $leve->location->name : '' }}</div>
		<div>{{ ! empty($leve->minor) ? $leve->minor->name : '' }}</div>
	</td>
	<td class='text-center valign'>
		<button class='btn btn-default leve_rewards' data-toggle='popover' data-content-id='#rewards_for_{{ $leve->id }}'>
			<i class='glyphicon glyphicon-gift'></i>
		</button>
		<div class='hidden' id='rewards_for_{{ $leve->id }}'>
			@foreach($leve_rewards[$leve->id] as $reward)
			<div class='margin-bottom'>
				@if($reward->item_id)
				<img src='/img/items/{{ $reward->item->icon ?: '../noitemicon' }}.png' style='margin-right: 10px;'>
				{{ $reward->item->name }}
				@else
				<img src='/img/noitemicon.png' style='margin-right: 10px;'>
				{{ $reward->item_name }}
				<span class='label label-danger' rel='tooltip' title='See news post to help fill this out!' data-container='body'>Help</span>
				@endif
				<span class='label label-success'>x {{ number_format($reward->amount) }}</span>
			</div>
			@endforeach
		</div>
	</td>
	<td class='text-center valign'>
		<button class='btn btn-default add-to-list' data-item-id='{{ $leve->item->id }}' data-item-name='{{{ $leve->item->name }}}' data-item-quantity='{{{ $leve->amount }}}'>
			<i class='glyphicon glyphicon-shopping-cart'></i>
			<i class='glyphicon glyphicon-plus'></i>
		</button>
	</td>
</tr>
@endforeach