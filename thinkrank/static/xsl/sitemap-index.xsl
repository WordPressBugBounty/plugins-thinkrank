<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9">
    <xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>
    
    <xsl:template match="/">
        <html xmlns="http://www.w3.org/1999/xhtml">
            <head>
                <title>XML Sitemap Index - ThinkRank SEO</title>
                <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
                <style type="text/css">
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                        margin: 0;
                        padding: 20px;
                        background-color: #f8f9fa;
                        color: #333;
                        line-height: 1.6;
                    }
                    .header {
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        color: white;
                        padding: 30px;
                        border-radius: 10px;
                        margin-bottom: 30px;
                        text-align: center;
                    }
                    .header h1 {
                        margin: 0;
                        font-size: 2.5em;
                        font-weight: 300;
                    }
                    .header p {
                        margin: 10px 0 0 0;
                        opacity: 0.9;
                        font-size: 1.1em;
                    }
                    .stats {
                        display: flex;
                        justify-content: center;
                        gap: 30px;
                        margin: 20px 0;
                        flex-wrap: wrap;
                    }
                    .stat-item {
                        background: rgba(255,255,255,0.2);
                        padding: 15px 25px;
                        border-radius: 8px;
                        text-align: center;
                    }
                    .stat-number {
                        font-size: 2em;
                        font-weight: bold;
                        display: block;
                    }
                    .stat-label {
                        font-size: 0.9em;
                        opacity: 0.8;
                    }
                    .container {
                        max-width: 1200px;
                        margin: 0 auto;
                        background: white;
                        border-radius: 10px;
                        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                        overflow: hidden;
                    }
                    .table-header {
                        background: #f8f9fa;
                        padding: 20px;
                        border-bottom: 2px solid #e9ecef;
                    }
                    .table-header h2 {
                        margin: 0;
                        color: #495057;
                        font-size: 1.5em;
                    }
                    .description {
                        background: #e3f2fd;
                        padding: 15px 20px;
                        border-left: 4px solid #2196f3;
                        margin: 20px;
                        border-radius: 4px;
                    }
                    .description p {
                        margin: 0;
                        color: #1565c0;
                        font-size: 0.95em;
                    }
                    table {
                        width: 100%;
                        border-collapse: collapse;
                        font-size: 14px;
                    }
                    th {
                        background: #6c757d;
                        color: white;
                        padding: 15px 10px;
                        text-align: left;
                        font-weight: 600;
                        text-transform: uppercase;
                        font-size: 12px;
                        letter-spacing: 0.5px;
                    }
                    td {
                        padding: 12px 10px;
                        border-bottom: 1px solid #e9ecef;
                        vertical-align: top;
                    }
                    tr:hover {
                        background-color: #f8f9fa;
                    }
                    .sitemap-cell {
                        max-width: 400px;
                        word-break: break-all;
                    }
                    .sitemap-cell a {
                        color: #007bff;
                        text-decoration: none;
                        font-weight: 500;
                    }
                    .sitemap-cell a:hover {
                        text-decoration: underline;
                        color: #0056b3;
                    }
                    .sitemap-type {
                        display: inline-block;
                        background: #28a745;
                        color: white;
                        padding: 4px 8px;
                        border-radius: 12px;
                        font-size: 11px;
                        font-weight: 600;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }
                    .lastmod {
                        color: #6c757d;
                        font-family: 'Courier New', monospace;
                        font-size: 13px;
                    }
                    .footer {
                        text-align: center;
                        margin-top: 40px;
                        padding: 20px;
                        color: #6c757d;
                        font-size: 14px;
                    }
                    .footer a {
                        color: #007bff;
                        text-decoration: none;
                    }
                    .footer a:hover {
                        text-decoration: underline;
                    }
                    
                    /* Responsive design */
                    @media (max-width: 768px) {
                        body { padding: 10px; }
                        .header { padding: 20px; }
                        .header h1 { font-size: 2em; }
                        .stats { gap: 15px; }
                        .stat-item { padding: 10px 15px; }
                        table { font-size: 12px; }
                        th, td { padding: 8px 6px; }
                        .sitemap-cell { max-width: 200px; }
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>XML Sitemap Index</h1>
                    <p>Generated by ThinkRank SEO Plugin</p>
                    <div class="stats">
                        <div class="stat-item">
                            <span class="stat-number"><xsl:value-of select="count(sitemap:sitemapindex/sitemap:sitemap)"/></span>
                            <span class="stat-label">Total Sitemaps</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-number">Today</span>
                            <span class="stat-label">Generated</span>
                        </div>
                    </div>
                </div>

                <div class="description">
                    <p><strong>What is a Sitemap Index?</strong> This file contains links to all the individual sitemaps for this website. Each sitemap contains specific types of content (posts, pages, products, etc.) to help search engines crawl and index your site more efficiently.</p>
                </div>

                <div class="container">
                    <div class="table-header">
                        <h2>Individual Sitemaps</h2>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Sitemap URL</th>
                                <th>Content Type</th>
                                <th>Last Modified</th>
                            </tr>
                        </thead>
                        <tbody>
                            <xsl:for-each select="sitemap:sitemapindex/sitemap:sitemap">
                                <tr>
                                    <td class="sitemap-cell">
                                        <a href="{sitemap:loc}" target="_blank">
                                            <xsl:value-of select="sitemap:loc"/>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="sitemap-type">
                                            <xsl:choose>
                                                <xsl:when test="contains(sitemap:loc, 'posts')">Posts</xsl:when>
                                                <xsl:when test="contains(sitemap:loc, 'pages')">Pages</xsl:when>
                                                <xsl:when test="contains(sitemap:loc, 'categories')">Categories</xsl:when>
                                                <xsl:when test="contains(sitemap:loc, 'products')">Products</xsl:when>
                                                <xsl:when test="contains(sitemap:loc, 'product-categories')">Product Categories</xsl:when>
                                                <xsl:otherwise>Content</xsl:otherwise>
                                            </xsl:choose>
                                        </span>
                                    </td>
                                    <td class="lastmod">
                                        <xsl:choose>
                                            <xsl:when test="sitemap:lastmod">
                                                <xsl:value-of select="substring(sitemap:lastmod, 1, 10)"/>
                                            </xsl:when>
                                            <xsl:otherwise>-</xsl:otherwise>
                                        </xsl:choose>
                                    </td>
                                </tr>
                            </xsl:for-each>
                        </tbody>
                    </table>
                </div>

                <div class="footer">
                    <p>This XML sitemap index helps search engines discover and crawl all your website's content efficiently.</p>
                    <p>Generated by <a href="https://wordpress.org/plugins/thinkrank/" target="_blank">ThinkRank SEO</a></p>
                </div>
            </body>
        </html>
    </xsl:template>
</xsl:stylesheet>
