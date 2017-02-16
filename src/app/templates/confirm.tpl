{include file="_head.tpl"}

<main>
<form method="post" action="{$url}">
{$token|raw}

{if $password}

	<article class="card">
		<header>
			<h3>Click to continue:</h3>
		</header>
		<section class="content">
			<p>Careful: the secret will only be displayed once.</p>
		</section>
		<footer>
			<input type="submit" class="button" name="continue" value="View secret" />
		</footer>
	</article>

{else}

	<article class="card">
		<header>
			<h3>Enter password to see this secret:</h3>
		</header>
		<section class="content">
			{if $error}
				<h3><span class="label error">Wrong password or corrupt secret.</span></h3>
			{/if}
			<input type="password" name="password" id="password" />
		</section>
		<footer>
			<input type="submit" value="Decrypt secret" class="button" />
		</footer>
	</article>

{/if}

</form>
</main>

{include file="_foot.tpl"}