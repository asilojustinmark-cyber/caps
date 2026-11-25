<?php /* Decorative artwork for the banner: two jerseys and a ball, drawn as SVG (no image files). */ ?>
<svg viewBox="0 0 640 300" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
  <defs>
    <linearGradient id="jd" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1b2330"/><stop offset="1" stop-color="#070a10"/></linearGradient>
    <linearGradient id="jw" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#cfd5df"/></linearGradient>
    <radialGradient id="ball" cx="38%" cy="32%" r="75%"><stop offset="0" stop-color="#9cc0ff"/><stop offset="1" stop-color="#1d4ed8"/></radialGradient>
    <radialGradient id="spot" cx="50%" cy="0%" r="80%"><stop offset="0" stop-color="#fff" stop-opacity=".22"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></radialGradient>
  </defs>
  <ellipse cx="360" cy="20" rx="210" ry="180" fill="url(#spot)"/>
  <!-- dark jersey -->
  <g transform="translate(150 28)">
    <path d="M70 0c10 24 52 24 62 0l54 22 18 62-34 8v170H32V92L-2 84l18-62z" fill="url(#jd)" stroke="#2c3748" stroke-width="2"/>
    <path d="M70 0c10 24 52 24 62 0" fill="none" stroke="#1d4ed8" stroke-width="7"/>
    <path d="M32 92L-2 84l18-62 54-22M186 22l18 62-34 8" fill="none" stroke="#1d4ed8" stroke-width="5"/>
    <path d="M32 92v178M170 92v178" stroke="#1d4ed8" stroke-width="6" fill="none"/><path d="M44 200h114M44 214h114" stroke="#1d4ed8" stroke-width="3" opacity=".7"/>
    <text x="101" y="165" text-anchor="middle" font-family="Barlow Condensed,Impact,Arial Narrow,sans-serif" font-weight="800" font-size="92" fill="#fff" stroke="#1d4ed8" stroke-width="2">23</text>
    <text x="101" y="70" text-anchor="middle" font-family="Barlow Condensed,Impact,Arial Narrow,sans-serif" font-weight="800" font-size="26" fill="#fff" letter-spacing="2">TEAM</text>
  </g>
  <!-- white jersey -->
  <g transform="translate(330 8)">
    <path d="M70 0c10 24 52 24 62 0l54 22 18 62-34 8v190H32V92L-2 84l18-62z" fill="url(#jw)" stroke="#9aa4b4" stroke-width="2"/>
    <path d="M70 0c10 24 52 24 62 0" fill="none" stroke="#111" stroke-width="7"/>
    <path d="M32 92L-2 84l18-62 54-22M186 22l18 62-34 8" fill="none" stroke="#1d4ed8" stroke-width="5"/>
    <text x="101" y="170" text-anchor="middle" font-family="Barlow Condensed,Impact,Arial Narrow,sans-serif" font-weight="800" font-size="104" fill="#0b2a6f" stroke="#1d4ed8" stroke-width="3">23</text>
    <text x="101" y="72" text-anchor="middle" font-family="Barlow Condensed,Impact,Arial Narrow,sans-serif" font-weight="800" font-size="30" fill="#0b2a6f" letter-spacing="2">TEAM</text>
  </g>
  <!-- ball -->
  <g transform="translate(30 186)">
    <circle cx="52" cy="52" r="52" fill="url(#ball)"/>
    <g fill="none" stroke="#2a1305" stroke-width="3" stroke-linecap="round"><path d="M52 0v104M0 52h104"/><path d="M16 18c18 14 18 54 0 68M88 18c-18 14-18 54 0 68"/></g>
  </g>
</svg>
