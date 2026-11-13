/**
 * ETPCP-AI System Evaluation Questionnaire — Google Form builder
 * Appendix C (ISO/IEC 25010, 30 items) as a Google Form.
 *
 * HOW TO USE
 *   1. Go to https://script.google.com and click "New project".
 *   2. Delete the sample code, paste this whole file, and click Save.
 *   3. In the function dropdown, choose  createEvaluationForm  and click Run.
 *      Allow the permissions Google asks for (it needs to create a Form and a Sheet
 *      in YOUR Drive).
 *   4. Open "Execution log" — it shows three links:
 *        - Form link to SEND to respondents
 *        - Edit link (for your group only)
 *        - Response sheet link
 *   5. After everyone has answered, choose  computeResults  and click Run.
 *      A "Results" tab is added to the response sheet with the weighted mean
 *      and verbal interpretation of every item, every characteristic, and the
 *      overall result — ready to copy into Chapter IV.
 *
 * Run createEvaluationForm only ONCE. Running it again makes a second form.
 */

const FORM_TITLE = 'ETPCP-AI System Evaluation Questionnaire';

const GROUPS = [
  'Owner (Admin)',
  'Store Staff',
  'Delivery Personnel',
  'Customer',
  'IT Student / IT Professional',
];

// Columns of every rating grid (5 = highest). computeResults() reads the first digit.
const SCALE = [
  '5 - Strongly Agree',
  '4 - Agree',
  '3 - Neutral',
  '2 - Disagree',
  '1 - Strongly Disagree',
];

const SECTIONS = [
  {
    title: 'A. Functional Suitability',
    help: 'The system performs the required tasks correctly and completely.',
    items: [
      'The system provides all the functions needed to browse, order, pay for, and track products.',
      'The AI Builder recommends builds with compatible parts that fit the stated budget.',
      'Order totals, stock counts, and reports are computed correctly.',
      'Staff can fully manage products, inventory, orders, and after-sales requests.',
      'The functions help users finish their tasks with fewer steps than the manual process.',
    ],
  },
  {
    title: 'B. Performance Efficiency',
    help: 'The system responds and processes requests within an acceptable time.',
    items: [
      'Pages load quickly.',
      'The AI Builder returns a recommendation within an acceptable time.',
      'Searching and filtering products respond quickly.',
      'Checkout and saving an order finish without delay.',
      'The system stays responsive when several users use it at the same time.',
    ],
  },
  {
    title: 'C. Compatibility',
    help: 'The system works across web browsers, devices, and connected services.',
    items: [
      'The system works on common web browsers such as Chrome, Edge, and Firefox.',
      'The system works on desktop computers, laptops, and mobile phones.',
      'Order QR codes and pickup codes can be scanned with a phone camera or scanner.',
      'The system works correctly with the payment gateway, email, and map services.',
      'Exported reports open correctly in spreadsheet software.',
    ],
  },
  {
    title: 'D. Usability',
    help: 'The system is easy to learn, navigate, and operate.',
    items: [
      'The system is easy to learn without formal training.',
      'Menus, buttons, and labels are clear and well organized.',
      'Error messages are clear and help users correct mistakes.',
      'The layout is attractive and consistent in both light and dark modes.',
      'The system is easy to use on a mobile phone screen.',
    ],
  },
  {
    title: 'E. Reliability',
    help: 'The system works consistently without errors or loss of data.',
    items: [
      'The system works without crashing or showing errors during use.',
      'Orders and stock records stay correct after repeated use.',
      'The system does not oversell stock when many orders are placed at the same time.',
      'The system handles failures, such as an unavailable AI service or a failed payment, without losing data.',
      'The system is available whenever it is needed during store hours.',
    ],
  },
  {
    title: 'F. Security',
    help: 'The system protects data and prevents access by unauthorized users.',
    items: [
      'The email verification code at login protects user accounts.',
      'Each user can only open the pages allowed for their role.',
      'Personal information such as names, addresses, and contact numbers is protected.',
      'Important staff and Admin actions are recorded in the audit trail.',
      'Pickup codes, password reset links, and invitation links expire after a set time.',
    ],
  },
];

/* ======================================================================
 * 1) BUILD THE FORM
 * ==================================================================== */
function createEvaluationForm() {
  const form = FormApp.create(FORM_TITLE);
  form
    .setDescription(
      'Global Reciprocal Colleges – College of Computer Studies\n\n' +
      'E-Commerce (B2C) Technology Product Configuration Platform with AI-Based ' +
      'Recommendation System Using Machine Learning for TechWorld Computer Store (ETPCP-AI)\n\n' +
      'Please answer this questionnaire after the system demonstration and hands-on use. ' +
      'It takes about 10 minutes. Your answers are confidential, will be reported only as ' +
      'averages, and will be used only for this study in accordance with Republic Act No. 10173, ' +
      'the Data Privacy Act of 2012.')
    .setProgressBar(true)
    .setCollectEmail(false)
    .setAllowResponseEdits(false)
    .setShowLinkToRespondAgain(false)
    .setConfirmationMessage('Thank you for your time and participation! Your response has been recorded.');

  // --- Section 1: Consent -------------------------------------------------
  const consent = form.addMultipleChoiceItem()
    .setTitle('Consent to Participate')
    .setHelpText('Participation is voluntary. You may skip the comments or stop at any time without any consequence. ' +
                 'Do you agree to take part in this evaluation?')
    .setRequired(true);

  // --- Section 2: Respondent information ----------------------------------
  const infoPage = form.addPageBreakItem()
    .setTitle('Respondent Information');
  consent.setChoices([
    consent.createChoice('Yes, I agree to take part.', infoPage),
    consent.createChoice('No, I do not agree.', FormApp.PageNavigationType.SUBMIT),
  ]);

  form.addTextItem()
    .setTitle('Name (optional)')
    .setRequired(false);

  form.addMultipleChoiceItem()
    .setTitle('Respondent Group')
    .setHelpText('Choose the group that describes you.')
    .setChoiceValues(GROUPS)
    .setRequired(true);

  // --- Rating scale guide --------------------------------------------------
  form.addSectionHeaderItem()
    .setTitle('Rating Scale')
    .setHelpText('Rate each statement based on your experience with the system:\n' +
                 '5 – Strongly Agree\n4 – Agree\n3 – Neutral\n2 – Disagree\n1 – Strongly Disagree');

  // --- Sections A–F: one page and one grid each ----------------------------
  let n = 1;
  SECTIONS.forEach(sec => {
    form.addPageBreakItem()
      .setTitle(sec.title)
      .setHelpText(sec.help);
    const rows = sec.items.map(t => (n++) + '. ' + t);
    form.addGridItem()
      .setTitle(sec.title)
      .setHelpText('Choose one rating for each statement.')
      .setRows(rows)
      .setColumns(SCALE)
      .setRequired(true);
  });

  // --- Comments -------------------------------------------------------------
  form.addPageBreakItem().setTitle('Comments and Suggestions');
  form.addParagraphTextItem()
    .setTitle('Comments and Suggestions (optional)')
    .setHelpText('Tell us what you liked, what was hard to use, or what we should improve.')
    .setRequired(false);

  // --- Response sheet --------------------------------------------------------
  const ss = SpreadsheetApp.create(FORM_TITLE + ' (Responses)');
  form.setDestination(FormApp.DestinationType.SPREADSHEET, ss.getId());

  PropertiesService.getScriptProperties().setProperties({
    FORM_ID: form.getId(),
    SHEET_ID: ss.getId(),
  });

  Logger.log('SEND THIS to respondents:  ' + form.getPublishedUrl());
  Logger.log('EDIT the form (group only): ' + form.getEditUrl());
  Logger.log('RESPONSE SHEET:             ' + ss.getUrl());
}

/* ======================================================================
 * 2) COMPUTE WEIGHTED MEANS  (run after the survey is finished)
 *    WM = Σ(f × r) / T, interpreted with the Chapter III rating scale.
 * ==================================================================== */
function interpret(wm) {
  if (wm >= 4.50) return 'Highly Acceptable';
  if (wm >= 3.50) return 'Acceptable';
  if (wm >= 2.50) return 'Moderately Acceptable';
  if (wm >= 1.50) return 'Fairly Acceptable';
  return 'Not Acceptable';
}

function computeResults() {
  const props = PropertiesService.getScriptProperties();
  const form = FormApp.openById(props.getProperty('FORM_ID'));
  const ss = SpreadsheetApp.openById(props.getProperty('SHEET_ID'));

  // Collect ratings per section/row, and count respondent groups.
  const sums = {}, counts = {}, freq = {};
  const groupCount = {};
  GROUPS.forEach(g => groupCount[g] = 0);
  let respondents = 0;

  form.getResponses().forEach(resp => {
    let agreed = false, rated = false;
    resp.getItemResponses().forEach(ir => {
      const title = ir.getItem().getTitle();
      const ans = ir.getResponse();
      if (title === 'Consent to Participate') agreed = String(ans).indexOf('Yes') === 0;
      if (title === 'Respondent Group' && groupCount[ans] !== undefined) groupCount[ans]++;
      const sec = SECTIONS.find(s => s.title === title);
      if (sec && Array.isArray(ans)) {
        rated = true;
        ans.forEach((a, row) => {
          const r = parseInt(String(a).charAt(0), 10);
          if (!r) return;
          const key = title + '|' + row;
          sums[key] = (sums[key] || 0) + r;
          counts[key] = (counts[key] || 0) + 1;
          freq[key] = freq[key] || { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
          freq[key][r]++;
        });
      }
    });
    if (agreed && rated) respondents++;
  });

  let sh = ss.getSheetByName('Results');
  if (sh) sh.clear(); else sh = ss.insertSheet('Results');

  const out = [];
  out.push(['ETPCP-AI Evaluation Results', '', '', '', '', '', '', '', '']);
  out.push(['Total respondents (consented and answered)', respondents, '', '', '', '', '', '', '']);
  out.push(['', '', '', '', '', '', '', '', '']);
  out.push(['Distribution of Respondents', 'Frequency', 'Percentage', '', '', '', '', '', '']);
  GROUPS.forEach(g => out.push([g, groupCount[g],
    respondents ? (groupCount[g] / respondents * 100).toFixed(2) + '%' : '', '', '', '', '', '', '']));
  out.push(['', '', '', '', '', '', '', '', '']);

  const summary = [];
  let n = 1;
  SECTIONS.forEach(sec => {
    out.push([sec.title, '5', '4', '3', '2', '1', 'T', 'Weighted Mean', 'Verbal Interpretation']);
    let secTotal = 0, secItems = 0;
    sec.items.forEach((text, row) => {
      const key = sec.title + '|' + row;
      const T = counts[key] || 0;
      const wm = T ? sums[key] / T : 0;
      const f = freq[key] || { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
      out.push([(n++) + '. ' + text, f[5], f[4], f[3], f[2], f[1], T,
        T ? wm.toFixed(2) : '', T ? interpret(wm) : '']);
      if (T) { secTotal += wm; secItems++; }
    });
    const secWm = secItems ? secTotal / secItems : 0;
    out.push(['Average Weighted Mean', '', '', '', '', '', '',
      secItems ? secWm.toFixed(2) : '', secItems ? interpret(secWm) : '']);
    out.push(['', '', '', '', '', '', '', '', '']);
    summary.push([sec.title.substring(3), secWm]);
  });

  // Summary table with rank
  out.push(['Summary of the Evaluation Results', 'Weighted Mean', 'Verbal Interpretation', 'Rank', '', '', '', '', '']);
  const ranked = summary.slice().sort((a, b) => b[1] - a[1]);
  summary.forEach(s => out.push([s[0], s[1].toFixed(2), interpret(s[1]),
    ranked.findIndex(r => r[0] === s[0]) + 1, '', '', '', '', '']));
  const overall = summary.reduce((a, s) => a + s[1], 0) / summary.length;
  out.push(['Overall Weighted Mean', overall.toFixed(2), interpret(overall), '', '', '', '', '', '']);

  sh.getRange(1, 1, out.length, 9).setValues(out);
  sh.getRange(1, 1).setFontWeight('bold').setFontSize(13);
  sh.setColumnWidth(1, 520);
  out.forEach((r, i) => {
    if (['Weighted Mean', 'Frequency', 'Verbal Interpretation'].some(h => r[1] === h || r[7] === h || r[2] === h) ||
        String(r[0]).indexOf('Average') === 0 || String(r[0]).indexOf('Overall') === 0) {
      sh.getRange(i + 1, 1, 1, 9).setFontWeight('bold');
    }
  });

  Logger.log('Results written to the "Results" tab: ' + ss.getUrl());
}
