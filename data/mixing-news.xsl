<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
 <xsl:output method="html" encoding="UTF-8" omit-xml-declaration="yes"/>
 <xsl:param name="category" select="''"/><xsl:param name="level" select="''"/><xsl:param name="q" select="''"/>
 <xsl:param name="sort" select="'date'"/><xsl:param name="order" select="'descending'"/>
 <xsl:param name="action" select="'xml.php'"/><xsl:param name="mode" select="'server'"/>
 <xsl:variable name="upper" select="'ABCDEFGHIJKLMNOPQRSTUVWXYZАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ'"/>
 <xsl:variable name="lower" select="'abcdefghijklmnopqrstuvwxyzабвгдеежзийклмнопрстуфхцчшщъыьэюя'"/>
 <xsl:variable name="query" select="translate(normalize-space($q),$upper,$lower)"/>
 <xsl:variable name="items" select="/catalog/news[($category='' or category=$category) and ($level='' or level=$level) and contains(translate(concat(title,' ',summary),$upper,$lower),$query)]"/>
 <xsl:template name="option">
  <xsl:param name="value"/><xsl:param name="selected"/><xsl:param name="label" select="$value"/>
  <option value="{$value}"><xsl:if test="$value=$selected"><xsl:attribute name="selected">selected</xsl:attribute></xsl:if><xsl:value-of select="$label"/></option>
 </xsl:template>
 <xsl:template match="/">
  <div id="catalog" data-mode="{$mode}">
   <form id="catalog-form" class="card catalog-filters" action="{$action}" method="get">
    <div class="field catalog-query"><label for="catalog-query">Что тебя интересует?</label><input id="catalog-query" name="q" type="search" maxlength="120" value="{$q}" placeholder="Например, голос или бас"/></div>
    <div class="field"><label for="catalog-category">Тема</label><select id="catalog-category" name="category">
     <xsl:call-template name="option"><xsl:with-param name="value" select="''"/><xsl:with-param name="label" select="'Все темы'"/><xsl:with-param name="selected" select="$category"/></xsl:call-template>
     <xsl:for-each select="catalog/news[not(category=preceding-sibling::news/category)]"><xsl:call-template name="option"><xsl:with-param name="value" select="category"/><xsl:with-param name="selected" select="$category"/></xsl:call-template></xsl:for-each>
    </select></div>
    <div class="field"><label for="catalog-level">Уровень</label><select id="catalog-level" name="level">
     <xsl:call-template name="option"><xsl:with-param name="value" select="''"/><xsl:with-param name="label" select="'Любой уровень'"/><xsl:with-param name="selected" select="$level"/></xsl:call-template>
     <xsl:for-each select="catalog/news[not(level=preceding-sibling::news/level)]"><xsl:call-template name="option"><xsl:with-param name="value" select="level"/><xsl:with-param name="selected" select="$level"/></xsl:call-template></xsl:for-each>
    </select></div>
    <div class="field"><label for="catalog-sort">Сортировать</label><select id="catalog-sort" name="sort">
     <xsl:call-template name="option"><xsl:with-param name="value" select="'date'"/><xsl:with-param name="label" select="'По дате'"/><xsl:with-param name="selected" select="$sort"/></xsl:call-template>
     <xsl:call-template name="option"><xsl:with-param name="value" select="'title'"/><xsl:with-param name="label" select="'По названию'"/><xsl:with-param name="selected" select="$sort"/></xsl:call-template>
     <xsl:call-template name="option"><xsl:with-param name="value" select="'minutes'"/><xsl:with-param name="label" select="'По времени чтения'"/><xsl:with-param name="selected" select="$sort"/></xsl:call-template>
    </select></div>
    <div class="field"><label for="catalog-order">Порядок</label><select id="catalog-order" name="order">
     <xsl:call-template name="option"><xsl:with-param name="value" select="'descending'"/><xsl:with-param name="label" select="'По убыванию'"/><xsl:with-param name="selected" select="$order"/></xsl:call-template>
     <xsl:call-template name="option"><xsl:with-param name="value" select="'ascending'"/><xsl:with-param name="label" select="'По возрастанию'"/><xsl:with-param name="selected" select="$order"/></xsl:call-template>
    </select></div>
    <div class="actions"><button class="btn primary" type="submit">Показать</button><a class="btn" id="catalog-reset" href="{$action}">Сбросить</a></div>
   </form>
   <p id="catalog-count" class="spaced" role="status" aria-live="polite">Найдено: <xsl:value-of select="count($items)"/> из <xsl:value-of select="count(catalog/news)"/></p>
   <div id="catalog-list" class="grid grid-3">
    <xsl:for-each select="$items">
     <xsl:sort select="number(minutes) * number($sort='minutes')" data-type="number" order="{$order}"/>
     <xsl:sort select="*[name()=$sort and $sort!='minutes']" data-type="text" order="{$order}" lang="ru"/>
     <xsl:sort select="@id" data-type="text" order="ascending"/>
     <article class="card catalog-item" id="{@id}" data-category="{category}" data-level="{level}" data-date="{date}" data-minutes="{minutes}" data-title="{title}">
      <div class="catalog-meta"><span class="pill"><xsl:value-of select="category"/></span><time datetime="{date}"><xsl:value-of select="substring(date,9,2)"/>.<xsl:value-of select="substring(date,6,2)"/>.<xsl:value-of select="substring(date,1,4)"/></time></div>
      <h2><xsl:value-of select="title"/></h2><p class="small muted"><xsl:value-of select="level"/> · <xsl:value-of select="minutes"/> мин чтения</p>
      <p class="catalog-summary"><xsl:value-of select="summary"/></p><a class="text-link" href="{link}">Разобраться подробнее →</a>
     </article>
    </xsl:for-each>
   </div>
   <p id="catalog-empty" class="notice"><xsl:if test="count($items)&gt;0"><xsl:attribute name="hidden">hidden</xsl:attribute></xsl:if>Ничего не найдено. Попробуй другую тему или сократи запрос.</p>
  </div>
 </xsl:template>
</xsl:stylesheet>
