/// Widget tests for the field app.
///
/// This file also exists to occupy a filename. `flutter create` writes a
/// counter-app template into `test/widget_test.dart` whenever the path is
/// free, and that template references a `MyApp` class this project has never
/// had — so `make mobile-setup` produced a repo that would not analyse. A
/// real test at the same path means the generator leaves it alone.
///
/// Everything here is a pure widget: no network, no controllers, no
/// SharedPreferences. The outbox logic is covered in outbox_test.dart.
library;

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:serviceproof_field/models/models.dart';
import 'package:serviceproof_field/ui/screens/claim_outcome_screen.dart';
import 'package:serviceproof_field/ui/theme.dart';
import 'package:serviceproof_field/ui/widgets/consent_notice.dart';

Claim claimWith({String? state, String? notes}) => Claim.fromJson(<String, dynamic>{
      'id': 'c1',
      'reference': 'CLM-1042',
      'status': 'RESOLVED',
      'claimed_at': '2026-08-19T18:00:00+00:00',
      'notes': notes,
      'work_order': <String, dynamic>{
        'reference': 'WO-1042',
        'customer': 'ABC Telecom Services',
        'site': 'Site A — Central Depot',
      },
      if (state != null) 'decision': <String, dynamic>{'state': state},
    });

Widget host(Widget child) => MaterialApp(theme: buildTheme(), home: child);

void main() {
  group('Claim outcome screen', () {
    testWidgets('shows the verdict and what it means for the technician',
        (WidgetTester tester) async {
      await tester.pumpWidget(host(ClaimOutcomeScreen(claim: claimWith(state: 'VERIFIED'))));

      expect(find.text('Verified'), findsOneWidget);
      expect(find.textContaining('Nothing more is needed from you'), findsOneWidget);
      // Twice on purpose: the app bar title and the record below it.
      expect(find.text('CLM-1042'), findsNWidgets(2));
      expect(find.text('WO-1042'), findsOneWidget);
    });

    testWidgets('a claim still being checked says so rather than showing nothing',
        (WidgetTester tester) async {
      await tester.pumpWidget(host(ClaimOutcomeScreen(claim: claimWith())));

      expect(find.text('Being checked'), findsOneWidget);
      expect(find.textContaining('The network check runs next'), findsOneWidget);
    });

    testWidgets('a contested claim explains that a person will look, and does not accuse',
        (WidgetTester tester) async {
      await tester.pumpWidget(host(ClaimOutcomeScreen(claim: claimWith(state: 'DISPUTED'))));

      expect(find.text('Needs review'), findsOneWidget);
      expect(find.textContaining('not an accusation'), findsOneWidget);
      expect(find.text('What happens now'.toUpperCase()), findsOneWidget);
      expect(find.textContaining('nothing to resubmit'), findsOneWidget);
    });

    testWidgets('an unavailable network is not presented as a failed job',
        (WidgetTester tester) async {
      await tester.pumpWidget(host(ClaimOutcomeScreen(claim: claimWith(state: 'UNVERIFIED'))));

      expect(find.text('Not verified'), findsOneWidget);
      expect(find.textContaining('Your claim still stands'), findsOneWidget);
    });

    testWidgets('no outcome screen ever accuses the technician', (WidgetTester tester) async {
      for (final state in <String?>['VERIFIED', 'PARTIAL', 'DISPUTED', 'UNVERIFIED', null]) {
        await tester.pumpWidget(host(ClaimOutcomeScreen(claim: claimWith(state: state))));

        for (final word in <String>['fraud', 'lying', 'false claim', 'suspicious']) {
          expect(
            find.textContaining(word, findRichText: true),
            findsNothing,
            reason: 'state $state used the word "$word"',
          );
        }
      }
    });

    testWidgets('the worker note is shown back to them when they wrote one',
        (WidgetTester tester) async {
      await tester.pumpWidget(
        host(ClaimOutcomeScreen(claim: claimWith(state: 'VERIFIED', notes: 'Replaced the module.'))),
      );

      expect(find.text('Replaced the module.'), findsOneWidget);
    });
  });

  group('Consent notice', () {
    testWidgets('tells the technician what the operator will be asked',
        (WidgetTester tester) async {
      await tester.pumpWidget(
        host(const Scaffold(body: SingleChildScrollView(child: ConsentNotice()))),
      );

      expect(find.textContaining('asks your mobile operator'), findsOneWidget);
      expect(find.textContaining('inside the site area'), findsOneWidget);
    });

    testWidgets('states the limits, not just the fact of asking',
        (WidgetTester tester) async {
      await tester.pumpWidget(
        host(const Scaffold(body: SingleChildScrollView(child: ConsentNotice()))),
      );

      // The four limits are the substance of the notice. Without them it is a
      // disclosure that a query happens, which is not the same as consent.
      expect(find.textContaining('not a map'), findsOneWidget);
      expect(find.textContaining('asked once'), findsOneWidget);
      expect(find.textContaining('Your name is never sent'), findsOneWidget);
      expect(find.textContaining('never sends its own GPS'), findsOneWidget);
    });

    testWidgets('names the device when the job has one', (WidgetTester tester) async {
      await tester.pumpWidget(
        host(const Scaffold(
          body: SingleChildScrollView(child: ConsentNotice(deviceReference: 'DEV-001')),
        )),
      );

      expect(find.textContaining('DEV-001'), findsOneWidget);
    });

    testWidgets('the compact form drops the limits but keeps the disclosure',
        (WidgetTester tester) async {
      await tester.pumpWidget(
        host(const Scaffold(body: SingleChildScrollView(child: ConsentNotice(compact: true)))),
      );

      expect(find.textContaining('asks your mobile operator'), findsOneWidget);
      expect(find.textContaining('not a map'), findsNothing);
    });
  });
}
