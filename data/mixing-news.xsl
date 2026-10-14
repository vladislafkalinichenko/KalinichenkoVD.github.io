<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
 <xsl:output method="html" encoding="UTF-8" omit-xml-declaration="yes"/>
 <xsl:param name="category" select="''"/><xsl:param name="level" select="''"/><xsl:param name="q" select="''"/>
 <xsl:param name="sort" select="'date'"/><xsl:param name="order" select="'descending'"/>
 <xsl:param name="action" select="'xml.php'"/><xsl:param name="mode" select="'server'"/>
 <xsl:variable name="upper" select="'ABCDEFGHIJKLMNOPQRSTUVWXYZАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ'"/>
 <xsl:variable name="lower" select="'abcdefghijklmnopqrstuvwxyzабвгдеежзийклмнопрстуфхцчшщъыьэюя'"/>
 <xsl:variable name="query" select="translate(normalize-space($q),$upper,$lower)"/>
 <xsl:variable name="items" select="/catalog/news[($category='' or category=$category) and ($level='' or level=$level) and contains(translate(concat(title,' ',date,' ',substring(date,9,2),'.',substring(date,6,2),'.',substring(date,1,4),' ',category,' ',level,' ',minutes,' ',summary,' ',link),$upper,$lower),$query)]"/>
 <xsl:template name="option">
  <xsl:param name="value"/><xsl:param name="selected"/><xsl:param name="label" select="$value"/>
  <option value="{$value}"><xsl:if test="$value=$selected"><xsl:attribute name="selected">selected</xsl:attribute></xsl:if><xsl:value-of select="$label"/></option>
 </xsl:template>
 <xsl:template match="/">
  <div id="catalog" data-mode="{$mode}">
   <form id="catalog-form" class="card catalog-filters" action="{$action}" method="get">
    <div class="field catalog-query"><label for="catalog-query">Поиск по всем колонкам</label><input id="catalog-query" name="q" type="search" maxlength="120" value="{$q}" placeholder="Текст, дата, тема, уровень, минуты или ссылка"/></div>
    <div class="field"><label for="catalog-category">Тема</label><select id="catalog-category" name="category">
     <xsl:call-template name="option"><xsl:with-param name="value" select="''"/><xsl:with-param name="label" select="'Все темы'"/><xsl:with-param name="selected" select="$category"/></xsl:call-template>
     <xsl:for-each select="catalog/news[not(category=preceding-sibling::news/category)]"><xsl:call-template name="option"><xsl:with-param name="value" select="category"/><xsl:with-param name="selected" select="$category"/></xsl:call-template></xsl:for-each>
    </select></div>
    <div class="field"><label for="catalog-level">Уровень</label><select id="catalog-level" name="level">
     <xsl:call-template name="option"><xsl:with-param name="value" select="''"/><xsl:with-param name="label" select="'Любой уровень'"/><xsl:with-param name="selected" select="$level"/></xsl:call-template>
     <xsl:for-each select="catalog/news[not(level=preceding-sibling::news/level)]"><xsl:call-template name="option"><xsl:with-param name="value" select="level"/><xsl:with-param name="selected" select="$level"/></xsl:call-template></xsl:for-each>
    </select></div>
    <div class="field"><label for="catalog-sort">Сортировать</label><select id="catalog-sort" name="sort">
     <xsl:call-template name="option"><xsl:with-param name="value" select="'title'"/><xsl:with-param name="label" select="'Название'"/><xsl:with-param name="selected" select="$sort"/></xsl:call-template>
     <xsl:call-template name="option"><xsl:with-param name="value" select="'date'"/><xsl:with-param name="label" select="'Дата'"/><xsl:with-param name="selected" select="$sort"/></xsl:call-template>
     <xsl:call-template name="option"><xsl:with-param name="value" select="'category'"/><xsl:with-param name="label" select="'Тема'"/><xsl:with-param name="selected" select="$sort"/></xsl:call-template>
     <xsl:call-template name="option"><xsl:with-param name="value" select="'level'"/><xsl:with-param name="label" select="'Уровень'"/><xsl:with-param name="selected" select="$sort"/></xsl:call-template>
     <xsl:call-template name="option"><xsl:with-param name="value" select="'minutes'"/><xsl:with-param name="label" select="'Минуты чтения'"/><xsl:with-param name="selected" select="$sort"/></xsl:call-template>
     <xsl:call-template name="option"><xsl:with-param name="value" select="'summary'"/><xsl:with-param name="label" select="'Текст'"/><xsl:with-param name="selected" select="$sort"/></xsl:call-template>
     <xsl:call-template name="option"><xsl:with-param name="value" select="'link'"/><xsl:with-param name="label" select="'Ссылка'"/><xsl:with-param name="selected" select="$sort"/></xsl:call-template>
    </select></div>
    <div class="field"><label for="catalog-order">Порядок</label><select id="catalog-order" name="order">
     <xsl:call-template name="option"><xsl:with-param name="value" select="'descending'"/><xsl:with-param name="label" select="'По убыванию'"/><xsl:with-param name="selected" select="$order"/></xsl:call-template>
     <xsl:call-template name="option"><xsl:with-param name="value" select="'ascending'"/><xsl:with-param name="label" select="'По возрастанию'"/><xsl:with-param name="selected" select="$order"/></xsl:call-template>
    </select></div>
    <div class="actions"><button class="btn primary" type="submit">Показать</button><a class="btn" id="catalog-reset" href="{$action}">Сбросить</a></div>
   </form>
   <p id="catalog-count" class="spaced" role="status" aria-live="polite">Найдено: <xsl:value-of select="count($items)"/> из <xsl:value-of select="count(catalog/news)"/></p>
   <p class="catalog-table-note">Сортировка работает по каждой колонке в обоих направлениях. На небольшом экране таблицу можно прокрутить вправо.</p>
   <div class="catalog-table-wrap" role="region" aria-label="Таблица заметок" tabindex="0"><table class="catalog-table"><caption>Заметки о сведении: семь полей каждой XML-записи</caption><thead><tr><th scope="col"><xsl:if test="$sort='title'"><xsl:attribute name="aria-sort"><xsl:value-of select="$order"/></xsl:attribute></xsl:if><button type="submit" form="catalog-form" class="sort-button" name="column" value="title">Название<xsl:choose><xsl:when test="$sort='title' and $order='ascending'"> ↑</xsl:when><xsl:when test="$sort='title'"> ↓</xsl:when><xsl:otherwise> ↕</xsl:otherwise></xsl:choose></button></th><th scope="col"><xsl:if test="$sort='date'"><xsl:attribute name="aria-sort"><xsl:value-of select="$order"/></xsl:attribute></xsl:if><button type="submit" form="catalog-form" class="sort-button" name="column" value="date">Дата<xsl:choose><xsl:when test="$sort='date' and $order='ascending'"> ↑</xsl:when><xsl:when test="$sort='date'"> ↓</xsl:when><xsl:otherwise> ↕</xsl:otherwise></xsl:choose></button></th><th scope="col"><xsl:if test="$sort='category'"><xsl:attribute name="aria-sort"><xsl:value-of select="$order"/></xsl:attribute></xsl:if><button type="submit" form="catalog-form" class="sort-button" name="column" value="category">Тема<xsl:choose><xsl:when test="$sort='category' and $order='ascending'"> ↑</xsl:when><xsl:when test="$sort='category'"> ↓</xsl:when><xsl:otherwise> ↕</xsl:otherwise></xsl:choose></button></th><th scope="col"><xsl:if test="$sort='level'"><xsl:attribute name="aria-sort"><xsl:value-of select="$order"/></xsl:attribute></xsl:if><button type="submit" form="catalog-form" class="sort-button" name="column" value="level">Уровень<xsl:choose><xsl:when test="$sort='level' and $order='ascending'"> ↑</xsl:when><xsl:when test="$sort='level'"> ↓</xsl:when><xsl:otherwise> ↕</xsl:otherwise></xsl:choose></button></th><th scope="col"><xsl:if test="$sort='minutes'"><xsl:attribute name="aria-sort"><xsl:value-of select="$order"/></xsl:attribute></xsl:if><button type="submit" form="catalog-form" class="sort-button" name="column" value="minutes">Минуты чтения<xsl:choose><xsl:when test="$sort='minutes' and $order='ascending'"> ↑</xsl:when><xsl:when test="$sort='minutes'"> ↓</xsl:when><xsl:otherwise> ↕</xsl:otherwise></xsl:choose></button></th><th scope="col"><xsl:if test="$sort='summary'"><xsl:attribute name="aria-sort"><xsl:value-of select="$order"/></xsl:attribute></xsl:if><button type="submit" form="catalog-form" class="sort-button" name="column" value="summary">Текст<xsl:choose><xsl:when test="$sort='summary' and $order='ascending'"> ↑</xsl:when><xsl:when test="$sort='summary'"> ↓</xsl:when><xsl:otherwise> ↕</xsl:otherwise></xsl:choose></button></th><th scope="col"><xsl:if test="$sort='link'"><xsl:attribute name="aria-sort"><xsl:value-of select="$order"/></xsl:attribute></xsl:if><button type="submit" form="catalog-form" class="sort-button" name="column" value="link">Ссылка<xsl:choose><xsl:when test="$sort='link' and $order='ascending'"> ↑</xsl:when><xsl:when test="$sort='link'"> ↓</xsl:when><xsl:otherwise> ↕</xsl:otherwise></xsl:choose></button></th></tr></thead>
   <tbody id="catalog-list">
    <xsl:for-each select="$items">
     <xsl:sort select="number(minutes) * number($sort='minutes')" data-type="number" order="{$order}"/>
     <xsl:sort select="*[name()=$sort and $sort!='minutes']" data-type="text" order="{$order}" lang="ru"/>
     <xsl:sort select="@id" data-type="text" order="ascending"/>
     <tr class="catalog-item" id="{@id}" data-category="{category}" data-level="{level}" data-date="{date}" data-minutes="{minutes}" data-title="{title}" data-summary="{summary}" data-link="{link}">
      <td><strong><xsl:value-of select="title"/></strong></td>
      <td><time datetime="{date}"><xsl:value-of select="substring(date,9,2)"/>.<xsl:value-of select="substring(date,6,2)"/>.<xsl:value-of select="substring(date,1,4)"/></time></td>
      <td><xsl:value-of select="category"/></td><td><xsl:value-of select="level"/></td><td><xsl:value-of select="minutes"/></td>
      <td class="catalog-summary"><xsl:value-of select="summary"/></td><td><a class="text-link" href="{link}"><xsl:value-of select="link"/></a></td>
     </tr>
    </xsl:for-each>
   </tbody></table></div>
   <p id="catalog-empty" class="notice"><xsl:if test="count($items)&gt;0"><xsl:attribute name="hidden">hidden</xsl:attribute></xsl:if>Ничего не найдено. Попробуй другую тему или сократи запрос.</p>
  </div>
 </xsl:template>
</xsl:stylesheet>
