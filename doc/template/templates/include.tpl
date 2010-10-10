{if count($includes) > 0}
<h4>Includes:</h4>
<div class="tags">
{section name=includes loop=$includes}
{$includes[includes].include_name}({$includes[includes].include_value})<br />
{include file="docblock.tpl" sdesc=$includes[includes].sdesc desc=$includes[includes].desc tags=$includes[includes].tags}
{/section}
</div>
{/if}
