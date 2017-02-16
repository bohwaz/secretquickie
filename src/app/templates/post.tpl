{include file="_head.tpl" need_login=true}

<main>

{if !empty($secret_url)}

	<article class="card">
		<header>
			<h3>Your secret has been saved</h3>
		</header>
		<p>
			Share this link:
			<input type="text" value="{$secret_url}" class="url" readonly="readonly" />
		</p>

		<p>
			The secret will be deleted at the first time it's been viewed.
		</p>

		<footer>
			<a class="button dangerous" href="{$secret_url}">View and delete this secret now</a>
		</footer>
	</article>

	<script type="text/javascript">
	{literal}
	var url = document.querySelector('input.url');
	url.onclick = function () { this.focus(); this.select(); };
	url.select();
	url.focus();
	{/literal}
	</script>

{else}

	<form method="post" action="{$config.APP_URL}post/">
	{$token|raw}

	<section id="postForm">
		<article>
			<header>
				<h2>Post a new secret</h2>
			</header>
			<section class="content">
				<fieldset>
					<textarea class="stack" name="secret" placeholder="Enter your secret here" cols="70" rows="7"></textarea>
					<select name="expiry" class="stack">
					{foreach ($expire_table as $hours=>$label)}
						<option value="{$hours}"{if $hours == 24} selected="selected"{/if}>Expires in {$label}</option>
					{/foreach}
					</select>
					<input class="stack" name="password" type="text" placeholder="Optional password" />
				</fieldset>
				<p><input type="submit" class="button" name="post" value="Create a secret link &rarr;" /></p>
			</section>
		</article>
	</section>

	<section class="modal">
		<input id="modal_wait" type="checkbox" />
		<span for="modal_wait" class="overlay"></span>
		<article>
			<header>
				<h3>Loading…</h3>
			</header>
		</article>
	</section>

	<section class="modal">
		<input id="modal_confirm" type="checkbox" />
		<a href="{$config.APP_URL}" class="overlay"></a>
		<article>
			<header>
				<h3>Your secret has been saved</h3>
				<a class="close" href="{$config.APP_URL}">&times;</a>
			</header>
			<section class="content">
				<p>
					Share this link:
					<input type="text" readonly="readonly" class="url" />
				</p>

				<p>
					The secret will be deleted at the first time it's been viewed.
				</p>
			</section>
			<footer>
				<a class="button copy" href="#">Copy</a>
				<a class="button dangerous burn" href="#">View and delete this secret now</a>
			</footer>
		</article>
	</section>

	</form>

	<script type="text/javascript" src="{$config.APP_URL}static/sodium.min.js?{$asset_version}" integrity="sha384-{$js_hashes['sodium.min.js']}"></script>
	<script type="text/javascript" src="{$config.APP_URL}static/post.js?{$asset_version}" integrity="sha384-{$js_hashes['post.js']}"></script>
{/if}

</main>

{include file="_foot.tpl"}