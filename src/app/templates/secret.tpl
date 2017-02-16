{include file="_head.tpl"}

<main>
{if $secret === null}

	<article class="card">
		<header>
			<h3><span class="label error">Error:</span> Expired secret</h3>
		</header>
	</article>

{elseif !$secret}

	<article class="card">
		<header>
			<h3><span class="label error">Error:</span> Invalid password</h3>
		</header>
		<footer>
			<a class="button" href="{$url}">Enter a different password</a>
		</footer>
	</article>

{else}

	<article class="card">
		<header>
			<h3>Here is the secret:</h3>
		</header>
		<section class="content">
			<textarea readonly="readonly" id="secret" cols="70" rows="15">{$secret}</textarea>
		</section>
		<footer>
			<p><span class="label">This secret has been deleted from our server.</span></p>
		</footer>
	</article>

	<script type="text/javascript">
	{literal}
	var t = document.querySelector('textarea');
	t.onclick = function () { this.focus(); this.select(); };
	t.select();
	t.focus();
	{/literal}
	</script>
{/if}
</main>

{include file="_foot.tpl"}