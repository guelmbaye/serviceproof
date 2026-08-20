import 'package:flutter/material.dart';

import '../models/models.dart';

/// The console's visual language, adapted for a phone held outdoors.
///
/// Same palette and the same rule that machine facts are set in machine
/// type — but larger targets, heavier weights and more contrast, because
/// this screen gets read in sunlight by someone wearing gloves.
class SpColors {
  const SpColors._();

  static const Color paper = Color(0xFFEDF0F3);
  static const Color panel = Color(0xFFFFFFFF);
  static const Color ink = Color(0xFF0A1330);
  static const Color ink2 = Color(0xFF59646F);
  static const Color ink3 = Color(0xFF8A94A0);
  static const Color rule = Color(0xFFD6DCE3);
  static const Color ruleSoft = Color(0xFFE6EAEE);
  /// Sampled from the mark. White on it clears 5.1:1, so it carries buttons.
  static const Color signal = Color(0xFF0060FC);

  /// Deepened for small text: the brand blue sits exactly on the AA threshold
  /// against the paper background, which is a limit rather than a margin.
  static const Color signalInk = Color(0xFF0050D8);
  static const Color signalWash = Color(0xFFE8F0FF);

  static const Color verified = Color(0xFF0E6E5C);
  static const Color partial = Color(0xFFA26400);
  static const Color disputed = Color(0xFFA3123F);

  /// Grey on purpose. An unverified claim is an absence of evidence, and
  /// colouring it like a failure would teach technicians to read a network
  /// outage as an accusation.
  static const Color unverified = Color(0xFF6B7785);

  static Color forState(DecisionState state) => switch (state) {
        DecisionState.verified => verified,
        DecisionState.partial => partial,
        DecisionState.disputed => disputed,
        DecisionState.unverified => unverified,
      };
}

/// Machine facts: references, timestamps, coordinates, radii.
const List<String> kMonoFallback = <String>[
  'Roboto Mono',
  'DejaVu Sans Mono',
  'Menlo',
  'monospace',
];

const TextStyle kMono = TextStyle(
  fontFamilyFallback: kMonoFallback,
  fontSize: 13,
  height: 1.4,
  color: SpColors.ink2,
  fontFeatures: <FontFeature>[FontFeature.tabularFigures()],
);

const TextStyle kEyebrow = TextStyle(
  fontFamilyFallback: kMonoFallback,
  fontSize: 11,
  height: 1.3,
  letterSpacing: 1.4,
  fontWeight: FontWeight.w500,
  color: SpColors.ink3,
);

ThemeData buildTheme() {
  final base = ThemeData.light(useMaterial3: true);

  return base.copyWith(
    scaffoldBackgroundColor: SpColors.paper,
    colorScheme: base.colorScheme.copyWith(
      primary: SpColors.signal,
      surface: SpColors.panel,
      error: SpColors.disputed,
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: SpColors.panel,
      foregroundColor: SpColors.ink,
      elevation: 0,
      scrolledUnderElevation: 0,
      shape: Border(bottom: BorderSide(color: SpColors.rule)),
      titleTextStyle: TextStyle(
        color: SpColors.ink,
        fontSize: 17,
        fontWeight: FontWeight.w600,
        letterSpacing: -0.2,
      ),
    ),
    textTheme: base.textTheme.apply(
      bodyColor: SpColors.ink,
      displayColor: SpColors.ink,
    ),
    dividerTheme: const DividerThemeData(
      color: SpColors.ruleSoft,
      thickness: 1,
      space: 1,
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: SpColors.signal,
        foregroundColor: Colors.white,
        // A 56pt target is what a gloved thumb actually hits.
        minimumSize: const Size.fromHeight(56),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(3)),
        textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: SpColors.ink,
        side: const BorderSide(color: SpColors.rule),
        minimumSize: const Size.fromHeight(52),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(3)),
        textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: SpColors.panel,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 16),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(3),
        borderSide: const BorderSide(color: SpColors.rule),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(3),
        borderSide: const BorderSide(color: SpColors.rule),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(3),
        borderSide: const BorderSide(color: SpColors.signal, width: 2),
      ),
      labelStyle: const TextStyle(color: SpColors.ink2),
    ),
    snackBarTheme: const SnackBarThemeData(
      backgroundColor: SpColors.ink,
      contentTextStyle: TextStyle(color: Colors.white, fontSize: 14),
      behavior: SnackBarBehavior.floating,
    ),
  );
}
