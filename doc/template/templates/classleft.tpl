{foreach key=subpackage item=files from=$classleftindex}
	<ul class="classes">
		{if $subpackage != ''}
		<li>{$subpackage}
			<ul>
		{/if}

{section name=files loop=$files}
		<li>{if $files[files].link != ''}<a href="{$files[files].link}">{/if}{$files[files].title}{if $files[files].link != ''}</a>{/if}</li>
{/section}

		{if $subpackage != ''}
			</ul>
		</li>
		{/if}
	</ul>
{/foreach}
