import 'package:flutter/material.dart';

import '../../models/models.dart';
import '../theme.dart';

/// A bordered white surface. The app's only container.
class SpPanel extends StatelessWidget {
  const SpPanel({super.key, required this.child, this.padding, this.accent});

  final Widget child;
  final EdgeInsetsGeometry? padding;

  /// A left rule in the decision colour, used when the panel carries a verdict.
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: SpColors.panel,
        border: Border(
          top: const BorderSide(color: SpColors.rule),
          right: const BorderSide(color: SpColors.rule),
          bottom: const BorderSide(color: SpColors.rule),
          left: accent != null
              ? BorderSide(color: accent!, width: 4)
              : const BorderSide(color: SpColors.rule),
        ),
        borderRadius: BorderRadius.circular(3),
      ),
      padding: padding ?? const EdgeInsets.all(16),
      child: child,
    );
  }
}

class Eyebrow extends StatelessWidget {
  const Eyebrow(this.text, {super.key, this.color});

  final String text;
  final Color? color;

  @override
  Widget build(BuildContext context) => Text(
        text.toUpperCase(),
        style: kEyebrow.copyWith(color: color),
      );
}

/// The decision, as a technician reads it.
class StateBadge extends StatelessWidget {
  const StateBadge(this.state, {super.key, this.large = false});

  final DecisionState state;
  final bool large;

  @override
  Widget build(BuildContext context) {
    final color = SpColors.forState(state);

    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: large ? 14 : 10,
        vertical: large ? 8 : 5,
      ),
      decoration: BoxDecoration(
        color: Color.alphaBlend(color.withValues(alpha: 0.08), Colors.white),
        border: Border.all(color: color.withValues(alpha: 0.35)),
        borderRadius: BorderRadius.circular(2),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Container(
            width: large ? 8 : 6,
            height: large ? 8 : 6,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          ),
          SizedBox(width: large ? 9 : 7),
          Text(
            state.label,
            style: TextStyle(
              color: color,
              fontSize: large ? 15 : 12.5,
              fontWeight: FontWeight.w600,
              letterSpacing: 0.1,
            ),
          ),
        ],
      ),
    );
  }
}

/// A label and a value, with the value in machine type when it is a fact
/// the system recorded rather than a sentence someone wrote.
class InfoRow extends StatelessWidget {
  const InfoRow(this.label, this.value, {super.key, this.mono = false});

  final String label;
  final String value;
  final bool mono;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          SizedBox(width: 116, child: Eyebrow(label)),
          Expanded(
            child: Text(
              value,
              style: mono
                  ? kMono.copyWith(color: SpColors.ink)
                  : const TextStyle(fontSize: 15, height: 1.35),
            ),
          ),
        ],
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.headline, this.hint});

  final String headline;
  final String? hint;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 48),
      child: Column(
        children: <Widget>[
          Text(
            headline,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 16, color: SpColors.ink2, height: 1.4),
          ),
          if (hint != null) ...<Widget>[
            const SizedBox(height: 8),
            Text(
              hint!,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 14, color: SpColors.ink3, height: 1.4),
            ),
          ],
        ],
      ),
    );
  }
}

String formatWhen(DateTime? value) {
  if (value == null) return '—';

  String two(int n) => n.toString().padLeft(2, '0');

  return '${two(value.day)}/${two(value.month)} ${two(value.hour)}:${two(value.minute)}';
}
